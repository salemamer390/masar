<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Models\MovementRecord;
use App\Services\Imports\EmployeeImportService;
use App\Services\Imports\WorkbookAttendanceImporter;
use App\Services\Imports\WorkbookInsuranceImporter;
use App\Services\Imports\WorkbookLeavePenaltyImporter;
use App\Services\Imports\WorkbookRequestImporter;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use App\Services\Imports\XlsxWriter;
use App\Models\PenaltyType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $service,
        private readonly XlsxWriter $writer,
        private readonly WorkbookAttendanceImporter $attendanceImporter,
        private readonly WorkbookLeavePenaltyImporter $leavePenaltyImporter,
        private readonly WorkbookRequestImporter $requestImporter,
        private readonly WorkbookInsuranceImporter $insuranceImporter,
    ) {
    }

    /** Download the ready-made Excel template. */
    public function template()
    {
        $path = resource_path('import-templates/employees-import-template.xlsx');
        abort_unless(is_file($path), 404, __('ملف النموذج غير موجود.'));

        return response()->download($path, 'employees-import-template.xlsx', ['Cache-Control' => 'no-store']);
    }

    /** Step 1: validate the file and report what would happen. Nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$analysis, $companyId] = $prepared;

        return response()->json(['success' => true] + $this->payload($analysis) + [
            'domains' => $this->simulateDomains($request, $analysis, $companyId),
            'penalty_types' => PenaltyType::withoutGlobalScopes()->orderBy('name')->pluck('name'),
            'review_token' => $this->reviewToken($request, $analysis, $companyId),
        ]);
    }

    /**
     * Full dry-run of the commit inside a transaction that is always rolled
     * back: the preview shows exactly what each workbook domain (attendance,
     * leaves, penalties, requests, insurance) would import or reject - nothing
     * is saved, and cross-sheet employee matching sees the employees this same
     * file would create.
     *
     * @param array<string, mixed> $analysis
     * @return array<string, array{imported: int, skipped: int, errors: array<int, string>}>
     */
    private function simulateDomains(Request $request, array $analysis, int $companyId): array
    {
        $file = $request->file('file');
        $extension = strtolower((string) $file?->getClientOriginalExtension());
        if ($extension !== 'xlsx') {
            return [];
        }

        try {
            DB::beginTransaction();
            try {
                $this->service->commit($analysis, $companyId);
                $distributed = $this->distribute($request, (string) $file->getRealPath(), $extension, $analysis['sheets'] ?? []);
            } finally {
                DB::rollBack();
            }
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        $domains = [];
        foreach (['attendance', 'leaves', 'penalties', 'requests', 'insurance'] as $domain) {
            $data = $distributed[$domain] ?? [];
            $imported = (int) ($data['imported'] ?? 0);
            $skipped = (int) ($data['skipped'] ?? 0);
            if ($imported + $skipped > 0) {
                $domains[$domain] = [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => array_slice((array) ($data['errors'] ?? []), 0, 5),
                    'warnings' => array_slice((array) ($data['warnings'] ?? []), 0, 10),
                ] + ($domain === 'penalties' ? ['failures' => array_slice((array) ($data['failures'] ?? []), 0, 25), 'excluded' => (int) ($data['excluded'] ?? 0)] : []);
            }
        }

        return $domains;
    }

    /** Step 2: import the valid rows. */
    public function store(Request $request): JsonResponse
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$analysis, $companyId] = $prepared;
        $payload = $this->payload($analysis);
        $extension = strtolower((string) $request->file('file')?->getClientOriginalExtension());

        // Import lineage tables come from migrations; never expose a raw SQL error.
        foreach (['employee_import_batches', 'employee_import_rows', 'employee_import_row_revisions', 'attendance_import_batches', 'attendance_import_days', 'attendance_punch_revisions'] as $table) {
            if (!Schema::hasTable($table)) {
                return response()->json(['success' => false, 'message' => __('قاعدة البيانات تحتاج تشغيل الترحيلات (php artisan migrate) قبل الاستيراد. لم يُحفظ أي شيء.')], 500);
            }
        }

        // The commit executes exactly the plan the user reviewed: the review
        // token binds the file contents, company, user, duplicates mode,
        // employee sheet choice and mappings, and every sheet's confirmed kind
        // and column mapping. Changing anything after the preview - the file,
        // a kind, or a mapping - fails closed and forces a fresh preview.
        $token = (string) $request->input('review_token', '');
        if ($token === '' || !hash_equals($this->reviewToken($request, $analysis, $companyId), $token)) {
            return response()->json(['success' => false, 'message' => __('التأكيد يتطلب مراجعة حديثة: أعد فحص الملف ثم أكّد الاستيراد.')] + $payload, 422);
        }

        // Reject unknown sheet-kind values outright instead of silently
        // falling back to detection and importing a different plan.
        $rawKinds = json_decode((string) $request->input('sheet_kinds', '{}'), true);
        $allowedKinds = array_merge(array_keys(EmployeeImportService::kindLabels()), ['ignore']);
        foreach ((is_array($rawKinds) ? $rawKinds : []) as $rawKind) {
            if (!in_array((string) $rawKind, $allowedKinds, true)) {
                return response()->json(['success' => false, 'message' => __('قيمة نوع ورقة غير معروفة. أعد فحص الملف وراجع أنواع الأوراق.')] + $payload, 422);
            }
        }

        // The reviewed column mapping must be complete enough to parse each
        // data sheet as its confirmed kind - enforced server-side, not only
        // in the modal.
        if ($extension === 'xlsx' && ($configError = $this->service->domainConfigError($analysis['sheets'] ?? [])) !== null) {
            return response()->json(['success' => false, 'message' => $configError] + $payload, 422);
        }

        if ($analysis['needs_mapping']) {
            return response()->json(['success' => false, 'message' => __('اربط عمود الأسماء بالحقل «الاسم الكامل» أولًا.')] + $payload, 422);
        }
        if (!empty($analysis['capacity']['exceeded'])) {
            return response()->json(['success' => false, 'message' => __('عدد الموظفين بعد الاستيراد يتجاوز حد باقتك. عدّل الملف أو رقّ الباقة.')] + $payload, 422);
        }
        if (($analysis['summary']['create'] + $analysis['summary']['update']) === 0) {
            return response()->json(['success' => false, 'message' => __('لا توجد صفوف صالحة للاستيراد.')] + $payload, 422);
        }
        if ($analysis['summary']['defaulted_dates'] > 0 && !$request->boolean('ack_dates')) {
            return response()->json(['success' => false, 'message' => __('أكّد موافقتك على اعتماد تاريخ اليوم كتاريخ انتهاء خدمة للموظفين غير المحدَّد لهم تاريخ.')] + $payload, 422);
        }

        if (($analysis['summary']['update'] ?? 0) > 0 && !$request->boolean('ack_updates')) {
            return response()->json(['success' => false, 'message' => __('يجب تأكيد تحديث بيانات الموظفين الموجودين مسبقًا.')] + $payload, 422);
        }

        // One atomic unit: employees, workbook domains and the lineage batch all
        // commit together or roll back together, so a failure never leaves data
        // without its review records (or half an import behind a raw error).
        [$result, $distributed, $batch] = DB::transaction(function () use ($request, $analysis, $companyId, $extension) {
            $result = $this->service->commit($analysis, $companyId);
            $distributed = $extension === 'xlsx'
                ? $this->distribute($request, (string) $request->file('file')->getRealPath(), $extension, $analysis['sheets'] ?? [])
                : [];

            return [$result, $distributed, $this->recordLineage($request, $analysis, $result, $distributed, $companyId)];
        });

        // The import the user just confirmed is the moment their manual column
        // links become trusted vocabulary: remember them for the next file.
        // Learning never blocks the import (no-op before the migration runs).
        $learnedAliases = $this->service->learnMappings(
            $companyId,
            json_decode((string) $request->input('mappings', '{}'), true),
            $analysis
        );

        $attendanceCount = (int) data_get($distributed, 'attendance.imported', 0);
        $leaveCount = (int) data_get($distributed, 'leaves.imported', 0);
        $penaltyCount = (int) data_get($distributed, 'penalties.imported', 0);
        $requestCount = (int) data_get($distributed, 'requests.imported', 0);
        $insuranceCount = (int) data_get($distributed, 'insurance.imported', 0);

        return response()->json([
            'success' => true,
            'message' => "تم الاستيراد: {$result['created']} موظف جديد، {$result['updated']} تم تحديثهم.",
            'result' => $result,
            'distributed' => $distributed,
            'distributed_attendance_count' => $attendanceCount,
            'distributed_leave_count' => $leaveCount,
            'distributed_penalty_count' => $penaltyCount,
            'distributed_request_count' => $requestCount,
            'distributed_insurance_count' => $insuranceCount,
            'batch_id' => $batch->id,
            'batches_url' => route('employees.import-batches.index'),
            'learned_aliases' => $learnedAliases,
        ] + $payload);
    }

    /** Excel file with the rejected rows (original values + reason) ready to be fixed and re-uploaded. */
    public function rejected(Request $request)
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$analysis] = $prepared;

        if (($analysis['summary']['error'] ?? 0) === 0) {
            return response()->json(['success' => false, 'message' => __('لا توجد صفوف مرفوضة.')], 422);
        }

        [$headers, $rows] = $this->service->rejectedSheet($analysis);
        $path = tempnam(sys_get_temp_dir(), 'rej');
        $this->writer->write($path, $headers, $rows);

        return response()->download($path, 'rejected-employees.xlsx', ['Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    /**
     * Run every workbook domain importer (attendance, leaves/penalties,
     * requests, insurance) against the uploaded file. Each importer is
     * permission-gated and isolated: a failure in one domain never blocks the
     * others, and per-row failures come back structured for the review queue.
     *
     * @return array<string, mixed>
     */
    /** Reviewed per-row penalty-type corrections: {sheet: {line: type name}}. */
    private function penaltyFixes(Request $request): array
    {
        $fixes = json_decode((string) $request->input('penalty_fixes', '{}'), true);
        if (!is_array($fixes)) return [];
        $clean = [];
        foreach ($fixes as $sheet => $lines) {
            if (!is_array($lines)) continue;
            foreach ($lines as $line => $type) {
                $type = trim((string) $type);
                if ($type === '__exclude__' || $type !== '') $clean[(string) $sheet][(int) $line] = $type;
            }
            if (count($clean) > 200) break;
        }

        return $clean;
    }

    private function distribute(Request $request, string $path, string $extension, array $sheets = []): array
    {
        // One classification, confirmed by the user in the preview, decides which
        // domain receives which sheet: dashboard/summary/ignored/unknown sheets
        // never reach a data importer, so a misclassified sheet cannot write
        // attendance (or any other domain) silently.
        $sheetNames = function (array $kinds) use ($sheets): array {
            // Fail safe: a sheet without a positively confirmed kind reaches no
            // importer - there is no legacy all-sheets fan-out from this path.
            $names = [];
            foreach ($sheets as $sheet) {
                if (in_array($sheet['kind'] ?? 'unknown', $kinds, true)) $names[] = (string) $sheet['name'];
            }
            return $names;
        };
        $sheetConfigs = function (array $kinds) use ($sheets): array {
            $configs = [];
            foreach ($sheets as $sheet) {
                $kind = (string) ($sheet['kind'] ?? 'unknown');
                if (!in_array($kind, $kinds, true) || empty($sheet['domain_config'])) continue;
                $configs[(string) $sheet['name']] = [
                    'header_row' => (int) ($sheet['domain_config']['header_row'] ?? -1),
                    'map' => (array) ($sheet['domain_config']['map'] ?? []),
                    'domain' => $kind,
                ];
            }
            return $configs;
        };
        $attendanceSheets = $sheetNames(['attendance']);
        $leavePenaltySheets = $sheetNames(['leaves', 'penalties']);
        $requestSheets = $sheetNames(['requests', 'advances']);
        $insuranceSheets = $sheetNames(['insurance']);
        $empty = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => [], 'failures' => []];

        $distributed = [];
        if ($request->user()?->hasPermission('attendance.create')) {
            if ($attendanceSheets === []) {
                $distributed['attendance'] = $empty;
            } else try {
                $distributed['attendance'] = $this->attendanceImporter->import(
                    $path, $extension,
                    app(PayrollPeriodGuard::class),
                    app(PayrollRunService::class),
                    $attendanceSheets,
                    $sheetConfigs(['attendance']),
                );
            } catch (\Throwable $exception) {
                $distributed['attendance'] = ['imported' => 0, 'skipped' => 0, 'errors' => ['تعذر توزيع ورقة الحضور: '.$exception->getMessage()], 'failures' => []];
            }
        }
        if ($request->user()?->hasPermission('leaves.create') || $request->user()?->hasPermission('penalties.create')) {
            try {
                $leavePenalty = $leavePenaltySheets === [] ? ['leaves' => $empty, 'penalties' => $empty] : $this->leavePenaltyImporter->import(
                    $path, $extension,
                    app(PayrollPeriodGuard::class), app(PayrollRunService::class),
                    (bool) $request->user()?->hasPermission('leaves.create'),
                    (bool) $request->user()?->hasPermission('penalties.create'),
                    $leavePenaltySheets,
                    $sheetConfigs(['leaves', 'penalties']),
                    $this->penaltyFixes($request),
                );
                $distributed['leaves'] = $leavePenalty['leaves'] ?? ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []];
                $distributed['penalties'] = $leavePenalty['penalties'] ?? ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []];
            } catch (\Throwable $exception) {
                $distributed['leaves'] = ['imported' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()], 'failures' => []];
                $distributed['penalties'] = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []];
            }
        }
        if ($request->user()?->hasPermission('requests.create')) {
            try {
                $distributed['requests'] = $requestSheets === [] ? $empty : $this->requestImporter->import(
                    $path, $extension,
                    app(PayrollPeriodGuard::class), app(PayrollRunService::class),
                    $requestSheets,
                    $sheetConfigs(['requests', 'advances']),
                );
            } catch (\Throwable $exception) {
                $distributed['requests'] = ['imported' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()], 'warnings' => [], 'failures' => []];
            }
        }
        if ($request->user()?->hasPermission('insurance.create')) {
            try {
                $distributed['insurance'] = $insuranceSheets === [] ? $empty : $this->insuranceImporter->import($path, $extension, $insuranceSheets, $sheetConfigs(['insurance']));
            } catch (\Throwable $exception) {
                $distributed['insurance'] = ['imported' => 0, 'skipped' => 0, 'errors' => ['تعذر توزيع ورقة التأمينات: '.$exception->getMessage()], 'failures' => []];
            }
        }

        return $distributed;
    }

    /**
     * Persist metadata-only import lineage and park the rejected rows for
     * in-app review. The raw workbook is never stored; only rejected rows
     * (encrypted) survive so a reviewer can fix them.
     *
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $result
     * @param array<string, mixed> $distributed
     */
    private function recordLineage(Request $request, array $analysis, array $result, array $distributed, int $companyId): EmployeeImportBatch
    {
        $sheets = [];
        foreach ($analysis['sheets'] ?? [] as $sheet) {
            $sheets[(string) ($sheet['name'] ?? '')] = (string) ($sheet['kind'] ?? 'unknown');
        }

        $distSummary = [];
        foreach (['attendance', 'leaves', 'penalties', 'requests', 'insurance'] as $domain) {
            $distSummary[$domain] = [
                'imported' => (int) data_get($distributed, $domain . '.imported', 0),
                'skipped' => (int) data_get($distributed, $domain . '.skipped', 0),
            ];
        }

        return DB::transaction(function () use ($request, $analysis, $result, $companyId, $sheets, $distSummary, $distributed): EmployeeImportBatch {
            $batch = EmployeeImportBatch::create([
                'company_id' => $companyId,
                'user_id' => $request->user()?->id,
                'file_name' => (string) ($request->file('file')?->getClientOriginalName() ?: 'workbook'),
                'sheets' => $sheets,
                'created' => (int) ($result['created'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'unchanged' => (int) ($result['unchanged'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'failed' => count($result['failed'] ?? []),
                'rejected' => (int) ($analysis['summary']['error'] ?? 0) + count($result['failed'] ?? [])
                    + array_sum(array_map(static fn ($domain) => count((array) data_get($distributed, $domain . '.failures', [])), ['attendance', 'leaves', 'penalties', 'requests', 'insurance'])),
                'distributed' => $distSummary,
            ]);

            if (!empty($result['created_ids'])) {
                Employee::withoutGlobalScopes()->where('company_id', $companyId)
                    ->whereIn('id', $result['created_ids'])->update(['import_batch_id' => $batch->id]);
            }
            if (!empty($result['movement_ids'])) {
                MovementRecord::withoutGlobalScopes()->whereIn('id', $result['movement_ids'])
                    ->update(['import_batch_id' => $batch->id]);
            }

            $failedLines = [];
            foreach ($result['failed'] ?? [] as $failed) {
                $failedLines[(int) $failed['line']] = (array) ($failed['messages'] ?? []);
            }
            foreach ($analysis['rows'] ?? [] as $row) {
                $isError = ($row['action'] ?? null) === 'error';
                $line = (int) ($row['line'] ?? 0);
                if (!$isError && !isset($failedLines[$line])) {
                    continue;
                }
                EmployeeImportRow::create([
                    'company_id' => $companyId,
                    'batch_id' => $batch->id,
                    'sheet' => $row['sheet'] ?? null,
                    'line' => $line,
                    'kind' => 'employees',
                    'employee_id' => $row['existing_id'] ?? null,
                    'status' => 'pending',
                    'payload' => $row['raw'] ?? [],
                    'errors' => $isError ? (array) ($row['errors'] ?? []) : $failedLines[$line],
                ]);
            }

            // Failed rows from the other workbook domains (attendance, leaves,
            // penalties, requests, insurance) are parked too - as review-only
            // entries: they are corrected in their own screens, never guessed here.
            // Rows the user explicitly excluded in the review are audit-dismissed,
            // never parked as pending again.
            foreach ((array) data_get($distributed, 'penalties.excluded_rows', []) as $excluded) {
                EmployeeImportRow::create([
                    'company_id' => $companyId,
                    'batch_id' => $batch->id,
                    'sheet' => (string) ($excluded['sheet'] ?? ''),
                    'line' => (int) ($excluded['line'] ?? 0),
                    'kind' => 'penalties',
                    'employee_id' => null,
                    'status' => 'dismissed',
                    'payload' => [
                        'headers' => (array) ($excluded['headers'] ?? []),
                        'values' => (array) ($excluded['values'] ?? []),
                    ],
                    'errors' => (array) ($excluded['errors'] ?? []),
                ]);
            }
            foreach (['attendance', 'leaves', 'penalties', 'requests', 'insurance'] as $domain) {
                foreach ((array) data_get($distributed, $domain . '.failures', []) as $failure) {
                    EmployeeImportRow::create([
                        'company_id' => $companyId,
                        'batch_id' => $batch->id,
                        'sheet' => (string) ($failure['sheet'] ?? ''),
                        'line' => (int) ($failure['line'] ?? 0),
                        'kind' => $domain,
                        'employee_id' => null,
                        'status' => 'pending',
                        'payload' => [
                            'headers' => (array) ($failure['headers'] ?? []),
                            'values' => (array) ($failure['values'] ?? []),
                        ],
                        'errors' => (array) ($failure['errors'] ?? []),
                    ]);
                }
            }

            return $batch;
        });
    }

    /**
     * Validates the request and analyses the uploaded file.
     *
     * @return array{0: array<string, mixed>, 1: int}|JsonResponse
     */
    /**
     * HMAC over the canonical reviewed plan: the uploaded file's hash, the
     * company and user, the duplicates mode, the employee sheet choice and
     * column mappings, and every sheet's effective kind plus its final domain
     * config (detected header row + map merged with the user's reviewed
     * overrides). Preview signs it; store recomputes and compares, so a commit
     * can only run the exact configuration the preview showed.
     *
     * @param array<string, mixed> $analysis
     */
    private function reviewToken(Request $request, array $analysis, int $companyId): string
    {
        $file = $request->file('file');
        $sheets = [];
        foreach ($analysis['sheets'] ?? [] as $sheet) {
            $config = $sheet['domain_config'] ?? null;
            $map = is_array($config) ? (array) ($config['map'] ?? []) : [];
            ksort($map);
            $sheets[] = [
                'name' => (string) ($sheet['name'] ?? ''),
                'kind' => (string) ($sheet['kind'] ?? 'unknown'),
                'header_row' => is_array($config) ? (int) ($config['header_row'] ?? -1) : null,
                'map' => $map,
            ];
        }
        $mappings = json_decode((string) $request->input('mappings', '{}'), true);
        $mappings = is_array($mappings) ? $mappings : [];
        ksort($mappings);
        $canonical = [
            'v' => 1,
            'file' => hash_file('sha256', (string) $file->getRealPath()),
            'company' => $companyId,
            'user' => (int) $request->user()->id,
            'duplicates' => (string) $request->input('duplicates', 'update'),
            'sheet' => (string) $request->input('sheet', 'auto'),
            'mappings' => $mappings,
            'sheets' => $sheets,
            'penalty_fixes' => $this->penaltyFixes($request),
        ];

        return hash_hmac('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    private function prepare(Request $request): array|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'duplicates' => ['nullable', 'in:update,skip'],
            'sheet' => ['nullable', 'string', 'max:10'],
            'mappings' => ['nullable', 'string', 'max:20000'],
            'sheet_kinds' => ['nullable', 'string', 'max:20000'],
            'domain_mappings' => ['nullable', 'string', 'max:20000'],
            'ack_dates' => ['nullable', 'boolean'],
            'ack_updates' => ['nullable', 'boolean'],
        ], [
            'file.required' => __('اختر ملف Excel أو CSV أولًا.'),
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.max' => __('حجم الملف يجب ألا يتجاوز 5 ميجابايت.'),
            'file.uploaded' => 'تعذر رفع الملف. تحقق من حجمه (الحد 5 ميجابايت) وحاول مرة أخرى.',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => __('يجب أن يكون الملف بصيغة xlsx أو csv. لتحويل ملف xls قديم: افتحه في Excel ثم احفظه باسم «Excel Workbook (.xlsx)».'),
            ], 422);
        }

        // Never infer a company for a platform account. Importing into the
        // first company in the database can mix tenants' employee data.
        $companyId = (int) auth()->user()->company_id;
        if ($companyId <= 0) {
            return response()->json(['success' => false, 'message' => __('لا توجد شركة مرتبطة بالحساب لاستيراد الموظفين إليها.')], 422);
        }

        $mappings = json_decode((string) $request->input('mappings', '{}'), true);
        $sheetKinds = json_decode((string) $request->input('sheet_kinds', '{}'), true);
        $domainMappings = json_decode((string) $request->input('domain_mappings', '{}'), true);
        $analysis = $this->service->analyze(
            $file->getRealPath(),
            $extension,
            $companyId,
            (string) $request->input('duplicates', 'update'),
            [
                'sheet' => (string) $request->input('sheet', 'auto'),
                'mappings' => is_array($mappings) ? $mappings : [],
                'sheet_kinds' => is_array($sheetKinds) ? $sheetKinds : [],
                'domain_mappings' => is_array($domainMappings) ? $domainMappings : [],
            ]
        );

        if ($analysis['file_errors'] !== []) {
            return response()->json(['success' => false, 'message' => $analysis['file_errors'][0]], 422);
        }

        return [$analysis, $companyId];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function payload(array $analysis): array
    {
        $fields = [];
        foreach (EmployeeImportService::COLUMNS as $key => [$label]) {
            $fields[] = ['key' => $key, 'label' => $label];
        }

        return [
            'summary' => $analysis['summary'],
            'capacity' => $analysis['capacity'],
            'new_lookups' => $analysis['new_lookups'],
            'unknown_columns' => $analysis['unknown_columns'],
            'sheets' => $analysis['sheets'],
            'selected_sheet' => $analysis['selected_sheet'],
            'merge_available' => $analysis['merge_available'],
            'needs_mapping' => $analysis['needs_mapping'],
            'columns' => $analysis['columns'],
            'field_usage' => (object) $analysis['field_usage'],
            'fields' => $fields,
            'today' => date('d/m/Y'),
            'kind_labels' => EmployeeImportService::kindLabels(),
            'domain_fields' => [
                'attendance' => EmployeeImportService::domainFields('attendance'),
                'leaves' => EmployeeImportService::domainFields('leaves'),
                'penalties' => EmployeeImportService::domainFields('penalties'),
                'requests' => EmployeeImportService::domainFields('requests'),
                'insurance' => EmployeeImportService::domainFields('insurance'),
            ],
            'review_sheets' => array_values(array_map(
                static fn (array $sheet): array => ['name' => $sheet['name'], 'rows' => $sheet['rows']],
                array_filter($analysis['sheets'] ?? [], static fn (array $sheet): bool => ($sheet['kind'] ?? 'unknown') === 'unknown' && (int) ($sheet['rows'] ?? 0) > 0)
            )),
            'rows' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'sheet' => $row['sheet'],
                'name' => $row['name'],
                'code' => $row['code'],
                'action' => $row['action'],
                'changes' => $row['changes'] ?? [],
                'errors' => $row['errors'],
                'warnings' => $row['warnings'],
            ], array_slice($analysis['rows'], 0, 150)),
        ];
    }
}
