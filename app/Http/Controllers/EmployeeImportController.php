<?php

namespace App\Http\Controllers;

use App\Services\Imports\EmployeeImportService;
use App\Services\Imports\WorkbookAttendanceImporter;
use App\Services\Imports\WorkbookLeavePenaltyImporter;
use App\Services\Imports\WorkbookRequestImporter;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use App\Services\Imports\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $service,
        private readonly XlsxWriter $writer,
        private readonly WorkbookAttendanceImporter $attendanceImporter,
        private readonly WorkbookLeavePenaltyImporter $leavePenaltyImporter,
        private readonly WorkbookRequestImporter $requestImporter,
    ) {
    }

    /** Download the ready-made Excel template. */
    public function template()
    {
        $path = resource_path('import-templates/employees-import-template.xlsx');
        abort_unless(is_file($path), 404, 'ملف النموذج غير موجود.');

        return response()->download($path, 'employees-import-template.xlsx', ['Cache-Control' => 'no-store']);
    }

    /** Step 1: validate the file and report what would happen. Nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        $prepared = $this->prepare($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$analysis] = $prepared;

        return response()->json(['success' => true] + $this->payload($analysis));
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

        if ($analysis['needs_mapping']) {
            return response()->json(['success' => false, 'message' => 'اربط عمود الأسماء بالحقل «الاسم الكامل» أولًا.'] + $payload, 422);
        }
        if (!empty($analysis['capacity']['exceeded'])) {
            return response()->json(['success' => false, 'message' => 'عدد الموظفين بعد الاستيراد يتجاوز حد باقتك. عدّل الملف أو رقّ الباقة.'] + $payload, 422);
        }
        if (($analysis['summary']['create'] + $analysis['summary']['update']) === 0) {
            return response()->json(['success' => false, 'message' => 'لا توجد صفوف صالحة للاستيراد.'] + $payload, 422);
        }
        if ($analysis['summary']['defaulted_dates'] > 0 && !$request->boolean('ack_dates')) {
            return response()->json(['success' => false, 'message' => 'أكّد موافقتك على اعتماد تاريخ اليوم كتاريخ انتهاء خدمة للموظفين غير المحدَّد لهم تاريخ.'] + $payload, 422);
        }

        if (($analysis['summary']['update'] ?? 0) > 0 && !$request->boolean('ack_updates')) {
            return response()->json(['success' => false, 'message' => 'يجب تأكيد تحديث بيانات الموظفين الموجودين مسبقًا.'] + $payload, 422);
        }

        $result = $this->service->commit($analysis, $companyId);
        $distributed = [];
        $extension = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        if ($extension === 'xlsx' && $request->user()?->hasPermission('attendance.create')) {
            try {
                $distributed['attendance'] = $this->attendanceImporter->import(
                    $request->file('file')->getRealPath(),
                    $extension,
                    app(PayrollPeriodGuard::class),
                    app(PayrollRunService::class),
                );
            } catch (\Throwable $exception) {
                $distributed['attendance'] = ['imported' => 0, 'skipped' => 0, 'errors' => ['تعذر توزيع ورقة الحضور: '.$exception->getMessage()]];
            }
        }
        if ($extension === 'xlsx') {
            try {
                if ($request->user()?->hasPermission('leaves.create') || $request->user()?->hasPermission('penalties.create')) {
                    $distributed['leave_penalty'] = $this->leavePenaltyImporter->import(
                        $request->file('file')->getRealPath(), $extension,
                        app(PayrollPeriodGuard::class), app(PayrollRunService::class),
                        (bool) $request->user()?->hasPermission('leaves.create'),
                        (bool) $request->user()?->hasPermission('penalties.create'),
                    );
                }
            } catch (\Throwable $exception) {
                $distributed['leave_penalty'] = ['leaves' => ['imported' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()]], 'penalties' => ['imported' => 0, 'skipped' => 0, 'errors' => []]];
            }
        }
        if ($extension === 'xlsx' && $request->user()?->hasPermission('requests.create')) {
            try {
                $distributed['requests'] = $this->requestImporter->import(
                    $request->file('file')->getRealPath(), $extension,
                    app(PayrollPeriodGuard::class), app(PayrollRunService::class),
                );
            } catch (\Throwable $exception) {
                $distributed['requests'] = ['imported' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()]];
            }
        }

        $attendanceCount = (int) data_get($distributed, 'attendance.imported', 0);
        $leaveCount = (int) data_get($distributed, 'leave_penalty.leaves.imported', 0);
        $penaltyCount = (int) data_get($distributed, 'leave_penalty.penalties.imported', 0);
        $requestCount = (int) data_get($distributed, 'requests.imported', 0);

        return response()->json([
            'success' => true,
            'message' => "تم الاستيراد: {$result['created']} موظف جديد، {$result['updated']} تم تحديثهم.",
            'result' => $result,
            'distributed' => $distributed,
            'distributed_attendance_count' => $attendanceCount,
            'distributed_leave_count' => $leaveCount,
            'distributed_penalty_count' => $penaltyCount,
            'distributed_request_count' => $requestCount,
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
            return response()->json(['success' => false, 'message' => 'لا توجد صفوف مرفوضة.'], 422);
        }

        [$headers, $rows] = $this->service->rejectedSheet($analysis);
        $path = tempnam(sys_get_temp_dir(), 'rej');
        $this->writer->write($path, $headers, $rows);

        return response()->download($path, 'rejected-employees.xlsx', ['Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    /**
     * Validates the request and analyses the uploaded file.
     *
     * @return array{0: array<string, mixed>, 1: int}|JsonResponse
     */
    private function prepare(Request $request): array|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'duplicates' => ['nullable', 'in:update,skip'],
            'sheet' => ['nullable', 'string', 'max:10'],
            'mappings' => ['nullable', 'string', 'max:20000'],
            'ack_dates' => ['nullable', 'boolean'],
            'ack_updates' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'اختر ملف Excel أو CSV أولًا.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.max' => 'حجم الملف يجب ألا يتجاوز 5 ميجابايت.',
            'file.uploaded' => 'تعذر رفع الملف. تحقق من حجمه (الحد 5 ميجابايت) وحاول مرة أخرى.',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'يجب أن يكون الملف بصيغة xlsx أو csv. لتحويل ملف xls قديم: افتحه في Excel ثم احفظه باسم «Excel Workbook (.xlsx)».',
            ], 422);
        }

        // Never infer a company for a platform account. Importing into the
        // first company in the database can mix tenants' employee data.
        $companyId = (int) auth()->user()->company_id;
        if ($companyId <= 0) {
            return response()->json(['success' => false, 'message' => 'لا توجد شركة مرتبطة بالحساب لاستيراد الموظفين إليها.'], 422);
        }

        $mappings = json_decode((string) $request->input('mappings', '{}'), true);
        $analysis = $this->service->analyze(
            $file->getRealPath(),
            $extension,
            $companyId,
            (string) $request->input('duplicates', 'update'),
            [
                'sheet' => (string) $request->input('sheet', 'auto'),
                'mappings' => is_array($mappings) ? $mappings : [],
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
            'rows' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'sheet' => $row['sheet'],
                'name' => $row['name'],
                'code' => $row['code'],
                'action' => $row['action'],
                'errors' => $row['errors'],
                'warnings' => $row['warnings'],
            ], array_slice($analysis['rows'], 0, 150)),
        ];
    }
}
