<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceMetricsService;
use App\Services\AttendancePeriodArchiveService;
use App\Services\HrOperationalSettingsService;
use App\Services\Imports\AttendanceSheetAnalyzer;
use App\Services\Imports\PunchAggregator;
use App\Services\Imports\SpreadsheetReader;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Attendance import with a mandatory preview: the workbook is analyzed, the
 * detected sheets/columns are shown for confirmation or remapping, and only
 * the confirmed unit is written. A preview key binds the commit to the exact
 * unit + mapping + options the user last reviewed; any change forces a fresh
 * preview. Tokens are bound to the uploading user and company, expire after
 * 30 minutes, and are consumed once. Existing records are never overwritten
 * silently: the preview counts them and requires explicit confirmation.
 */
class AttendanceImportController extends Controller
{
    private const TOKEN_TTL_MINUTES = 30;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly HrOperationalSettingsService $settings,
        private readonly AttendanceSheetAnalyzer $analyzer,
    ) {}

    public function preview(Request $request)
    {
        $request->validate([
            'file' => ['required_without:import_token', 'file', 'max:20480', 'mimes:xlsx,csv,txt'],
            'import_token' => ['nullable', 'uuid'],
        ]);

        $selectedUnit = null;
        $postedMap = null;
        $postedOptions = null;
        if ($request->hasFile('file')) {
            $this->purgeStaleImports();
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');
            try {
                $sheets = $this->reader->readAll($file->getRealPath(), $extension === 'txt' ? 'csv' : $extension);
            } catch (\Throwable $exception) {
                return back()->withErrors(['file' => __('تعذر قراءة الملف:') . $exception->getMessage()]);
            }
            $token = (string) Str::uuid();
            Storage::disk('local')->put("imports/{$token}." . ($extension === 'txt' ? 'csv' : $extension), file_get_contents($file->getRealPath()));
            Storage::disk('local')->put("imports/{$token}.json", json_encode([
                'company_id' => (int) $request->user()->company_id,
                'user_id' => (int) $request->user()->id,
                'file_name' => $file->getClientOriginalName(),
                'created_at' => now()->toIso8601String(),
            ]));
            $fileName = $file->getClientOriginalName();
        } else {
            $this->purgeStaleImports();
            $token = (string) $request->input('import_token');
            $temp = $this->loadTempImport($token, $request);
            if (!$temp) {
                return redirect()->route('attendance.index')->withErrors(['file' => __('انتهت صلاحية المعاينة. أعد رفع الملف.')]);
            }
            [$sheets, $fileName] = $temp;
            $selectedUnit = $request->input('unit');
            $postedMap = $this->sanitisePostedMap($request->input('map', []));
            $postedOptions = $this->sanitisePostedOptions($request->input('options', []));
        }

        $analysis = $this->analyzer->analyze($sheets);
        [$units, $skipped] = $this->buildUnits($analysis);
        if (!$units) {
            $this->deleteTempImport($token);
            $reasons = collect($skipped)->map(fn ($s) => "ورقة «{$s['name']}»: {$s['skip_reason']}")->implode(' ');
            return back()->withErrors(['file' => __('لم يُعثر على ورقة حضور قابلة للاستيراد.') . $reasons]);
        }
        return $this->renderPreview($token, $fileName, $units, $skipped, $sheets, $selectedUnit, $postedMap, $postedOptions);
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $request->validate([
            'import_token' => ['required', 'uuid'],
            'unit' => ['required', 'string'],
            'map' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'preview_key' => ['nullable', 'string', 'size:64'],
        ]);

        $this->purgeStaleImports();
        $token = $validated['import_token'];

        $lock = Cache::lock('attendance-import:' . $token, 60);
        if (!$lock->get()) {
            return redirect()->route('attendance.index')->withErrors(['file' => __('استيراد هذا الملف قيد التنفيذ بالفعل. انتظر ثوانيًا ثم أعد المحاولة.')]);
        }

        try {
            // Loaded inside the lock: a consumed token can never be replayed by a concurrent request.
            $temp = $this->loadTempImport($token, $request);
            if (!$temp) {
                return redirect()->route('attendance.index')->withErrors(['file' => __('انتهت صلاحية المعاينة. أعد رفع الملف.')]);
            }
            [$sheets, $fileName] = $temp;
            $analysis = $this->analyzer->analyze($sheets);
            [$units, $skipped] = $this->buildUnits($analysis);
            $unit = collect($units)->firstWhere('key', $validated['unit']);
            if (!$unit) {
                $this->deleteTempImport($token);
                return redirect()->route('attendance.index')->withErrors(['file' => __('اختيار الورقة لم يعد صالحًا. أعد رفع الملف.')]);
            }

            $postedMap = $this->sanitisePostedMap($validated['map'] ?? []);
            $postedOptions = $this->sanitisePostedOptions($validated['options'] ?? []);
            $map = $this->applyPostedMap($unit['map'], $postedMap, count($unit['headers']));
            $options = $this->normaliseOptions($postedOptions);

            // The commit must match the exact interpretation last shown to the user.
            if (!hash_equals($this->previewKey($token, $unit['key'], $map, $options), (string) $request->input('preview_key', ''))) {
                return $this->renderPreview($token, $fileName, $units, $skipped, $sheets, $unit['key'], $postedMap, $postedOptions,
                    'تغيّرت الورقة أو التعيين أو الخيارات بعد آخر معاينة. راجع المعاينة المحدّثة ثم اضغط «اعتماد واستيراد».');
            }
            if ($error = $this->validateMapForLayout($unit['layout'], $map)) {
                return $this->renderPreview($token, $fileName, $units, $skipped, $sheets, $unit['key'], $postedMap, $postedOptions, $error);
            }
            if ($this->unitHasAmbiguousDates($unit, $map, $sheets) && $options['date_format'] === '') {
                return $this->renderPreview($token, $fileName, $units, $skipped, $sheets, $unit['key'], $postedMap, $postedOptions,
                    'الورقة تحتوي تواريخ نصية غامضة مثل 03/04/2026. حدد «تنسيق التاريخ النصي» ثم حدّث المعاينة.');
            }

            $unit['map'] = $map;
            [$rows, $parseErrors] = $this->parseUnit($unit, $map, $sheets, $options);

            // Existing records are never replaced silently.
            $overwriteStats = $this->overwriteStats($rows);
            if ($overwriteStats['total'] > 0) abort_unless($request->user()->hasPermission('attendance.edit'),403);
            if ($overwriteStats['total'] > 0 && !$options['overwrite']) {
                return $this->renderPreview($token, $fileName, $units, $skipped, $sheets, $unit['key'], $postedMap, $postedOptions,
                    "سيُحدَّث {$overwriteStats['total']} سجلًا موجودًا"
                    . ($overwriteStats['manual'] ? " (منها {$overwriteStats['manual']} مُدخل يدويًا أو معتمد)" : '')
                    . '. أكّد خيار التحديث في المعاينة ثم حدّث المعاينة قبل الاعتماد.');
            }

            $batch = \App\Models\AttendanceImportBatch::create([
                'company_id' => $request->user()->company_id, 'user_id' => $request->user()->id,
                'file_name' => $fileName, 'layout' => $unit['layout'],
                'original_data' => ['unit' => $unit, 'map' => $map, 'options' => $options, 'sheets' => array_values(array_map(fn ($sheet) => $sheets[$sheet['index']], $unit['sheets'])), 'errors' => $parseErrors],
            ]);
            $deferred = 0; $imported = 0; $skippedRows = 0; $errors = $parseErrors; $affectedEmployeeIds = [];
            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($row, $batch, &$imported, &$affectedEmployeeIds) {
                        $periodGuard = app(PayrollPeriodGuard::class);
                        $periodGuard->ensureOpen($row['date']);
                        app(AttendancePeriodArchiveService::class)->ensureDateOpen($row['date']);
                        $punches = $row['punches'] ?? array_values(array_filter([
                            $row['check_in'] ? ['slot' => 'in', 'time' => $row['check_in'], 'ref' => $row['ref']] : null,
                            $row['check_out'] ? ['slot' => 'out', 'time' => $row['check_out'], 'ref' => $row['ref']] : null,
                        ]));
                        $issue = $row['issue'] ?? (($row['check_in'] || $row['check_out']) && (!$row['check_in'] || !$row['check_out']) ? 'missing' : null);
                        $lineage = \App\Models\AttendanceImportDay::create([
                            'batch_id' => $batch->id, 'employee_id' => $row['employee']->id, 'work_date' => $row['date'],
                            'original_punches' => $punches, 'current_punches' => $punches,
                            'issue' => $issue, 'resolution' => $issue ? 'pending' : 'resolved',
                        ]);
                        if ($issue === 'extra') return; // saved for explicit review; never silently reduce 3+ punches
                        $metrics = app(AttendanceMetricsService::class)->metrics($row['employee'], $row['check_in'], $row['check_out'], $row['status']);
                        $attendance = Attendance::updateOrCreate(
                            ['employee_id' => $row['employee']->id, 'attendance_date' => $row['date']],
                            ['check_in' => $row['check_in'], 'check_out' => $row['check_out'], ...$metrics, 'source' => 'import', 'approval_status' => 'Approved', 'approved_by' => null, 'approved_at' => null]
                        );
                        $lineage->update(['attendance_id' => $attendance->id]);
                        $affectedEmployeeIds[] = (int) $row['employee']->id;
                    });
                    if (($row['issue'] ?? null) !== 'extra') $imported++; else $deferred++;
                } catch (\Throwable $exception) {
                    $skippedRows++;
                    if (count($errors) < 25) $errors[] = "{$row['ref']}: {$exception->getMessage()}";
                }
            }

            $refreshWarnings = 0;
            foreach (array_unique($affectedEmployeeIds) as $employeeId) {
                try {
                    $payrollRuns->refreshEmployeeInOpenDrafts($employeeId);
                } catch (\Throwable $exception) {
                    $refreshWarnings++;
                }
            }
            if ($refreshWarnings > 0 && count($errors) < 25) {
                $errors[] = "تعذر تحديث مسودات الرواتب المفتوحة لـ {$refreshWarnings} موظفًا؛ السجلات استُوردت، أعد حساب المسودات يدويًا.";
            }

            $this->deleteTempImport($token);
            ActivityLog::record('attendance_import', 'Attendance records imported.', null, null, ['imported' => $imported, 'skipped' => $skippedRows, 'refresh_warnings' => $refreshWarnings, 'errors' => $errors], 'attendance');
            $message = "تم استيراد {$imported} سجل حضور"
                . ($deferred ? " وحفظ {$deferred} يومًا باستثناءات للمراجعة" : '')
                . ($skippedRows ? " وتخطي {$skippedRows} سجل بأخطاء" : '')
                . ($refreshWarnings ? " مع تنبيه في تحديث مسودات الرواتب" : '') . '.';
            return redirect()->route('attendance.index')->with('toast', $message)->with('attendance_import_errors', $errors);
        } finally {
            $lock->release();
        }
    }

    /** Render the preview page; posted map/options overrides drive the shown stats/sample for the selected unit. */
    private function renderPreview(string $token, string $fileName, array $units, array $skipped, array $sheets, ?string $selectedUnit, ?array $postedMap, ?array $postedOptions, ?string $error = null)
    {
        $selected = collect($units)->firstWhere('key', $selectedUnit) ?? $units[0];
        foreach ($units as &$unit) {
            $unit['options'] = [];
            if ($postedMap !== null && $unit['key'] === $selected['key']) {
                $unit['map'] = $this->applyPostedMap($unit['map'], $postedMap, count($unit['headers']));
            }
            if ($postedOptions !== null && $unit['key'] === $selected['key']) {
                $unit['options'] = $this->normaliseOptions($postedOptions);
            }
            $unit['preview'] = $this->previewUnit($unit, $sheets);
        }
        unset($unit);
        $selected = collect($units)->firstWhere('key', $selected['key']);
        return response()->view('attendance.import-preview', [
            'token' => $token,
            'fileName' => $fileName,
            'units' => $units,
            'skipped' => $skipped,
            'fields' => AttendanceSheetAnalyzer::MAPPABLE_FIELDS,
            'fieldLabels' => $this->fieldLabels(),
            'selectedUnit' => $selected['key'],
            'previewKey' => $this->previewKey($token, $selected['key'], $selected['map'], $this->normaliseOptions($selected['options'] ?? [])),
            'error' => $error,
        ], $error ? 422 : 200);
    }

    /** @return array{0: array, 1: string}|null sheets + original file name when the token is valid for this user. */
    private function loadTempImport(string $token, Request $request): ?array
    {
        $file = collect(Storage::disk('local')->files('imports'))->first(fn ($f) => str_starts_with(basename($f), $token . '.') && !str_ends_with($f, '.json'));
        $metaRaw = Storage::disk('local')->exists("imports/{$token}.json") ? Storage::disk('local')->get("imports/{$token}.json") : null;
        $meta = $metaRaw ? json_decode($metaRaw, true) : null;
        if (!$file || !is_array($meta)) { $this->deleteTempImport($token); return null; }
        if ((int) ($meta['company_id'] ?? 0) !== (int) $request->user()->company_id) return null;
        if ((int) ($meta['user_id'] ?? 0) !== (int) $request->user()->id) return null;
        try { $createdAt = isset($meta['created_at']) ? Carbon::parse($meta['created_at']) : null; } catch (\Throwable) { $createdAt = null; }
        if (!$createdAt || $createdAt->lt(now()->subMinutes(self::TOKEN_TTL_MINUTES))) {
            $this->deleteTempImport($token);
            return null;
        }
        try {
            $sheets = $this->reader->readAll(Storage::disk('local')->path($file), pathinfo($file, PATHINFO_EXTENSION));
        } catch (\Throwable) {
            $this->deleteTempImport($token);
            return null;
        }
        return [$sheets, (string) ($meta['file_name'] ?? basename($file))];
    }

    private function deleteTempImport(string $token): void
    {
        foreach (Storage::disk('local')->files('imports') as $file) {
            if (str_starts_with(basename($file), $token . '.')) Storage::disk('local')->delete($file);
        }
    }

    /** Abandoned previews leave private workbooks on disk; pruned on import requests and hourly by the scheduler. */
    private function purgeStaleImports(): void
    {
        self::purgeStaleImportFiles(self::TOKEN_TTL_MINUTES);
    }

    public static function purgeStaleImportFiles(int $ttlMinutes = self::TOKEN_TTL_MINUTES): void
    {
        $cutoff = now()->subMinutes($ttlMinutes);
        foreach (Storage::disk('local')->files('imports') as $file) {
            if (!Storage::disk('local')->exists($file)) continue;
            if (!str_ends_with($file, '.json')) {
                $metaPath = 'imports/' . pathinfo(basename($file), PATHINFO_FILENAME) . '.json';
                if (!Storage::disk('local')->exists($metaPath) && Storage::disk('local')->lastModified($file) < $cutoff->timestamp) Storage::disk('local')->delete($file);
                continue;
            }
            $meta = json_decode((string) Storage::disk('local')->get($file), true);
            try { $createdAt = is_array($meta) && isset($meta['created_at']) ? Carbon::parse($meta['created_at']) : null; } catch (\Throwable) { $createdAt = null; }
            if (!$createdAt || $createdAt->lt($cutoff)) {
                $token = pathinfo(basename($file), PATHINFO_FILENAME);
                foreach (Storage::disk('local')->files('imports') as $candidate) {
                    if (str_starts_with(basename($candidate), $token . '.')) Storage::disk('local')->delete($candidate);
                }
            }
        }
    }

    private function previewKey(string $token, string $unitKey, array $map, array $options): string
    {
        ksort($map);
        ksort($options);
        return hash('sha256', $token . '|' . $unitKey . '|' . json_encode($map) . '|' . json_encode($options));
    }

    /** Keep only known fields; values are '' (unassign) or a column index. */
    private function sanitisePostedMap(array $map): array
    {
        $clean = [];
        foreach ($map as $field => $column) {
            if (!in_array($field, AttendanceSheetAnalyzer::MAPPABLE_FIELDS, true)) continue;
            $clean[$field] = $column === '' || $column === null ? '' : (int) $column;
        }
        return $clean;
    }

    /** Keep only known option keys; values are whitelisted. */
    private function sanitisePostedOptions(array $options): array
    {
        $clean = [];
        if (array_key_exists('overwrite', $options)) $clean['overwrite'] = (bool) $options['overwrite'];
        if (array_key_exists('date_format', $options) && in_array($options['date_format'], ['dmy', 'mdy'], true)) $clean['date_format'] = $options['date_format'];
        return $clean;
    }

    private function normaliseOptions(array $options): array
    {
        return [
            'overwrite' => !empty($options['overwrite']),
            'date_format' => in_array($options['date_format'] ?? '', ['dmy', 'mdy'], true) ? $options['date_format'] : '',
        ];
    }

    private function applyPostedMap(array $autoMap, array $postedMap, int $columnCount): array
    {
        $map = $autoMap;
        foreach ($postedMap as $field => $column) {
            if ($column === '' || $column < 0 || $column >= max(1, $columnCount)) {
                unset($map[$field]);
            } else {
                $map[$field] = $column;
            }
        }
        return $map;
    }

    /**
     * Group analyzed sheets into importable units. Daily and punch sheets are
     * one unit each; per-employee sheets with the same table shape form one
     * combined unit. Everything else is reported, never dropped silently.
     */
    private function buildUnits(array $analysis): array
    {
        $units = [];
        $skipped = [];
        $perEmployeeGroups = [];
        foreach ($analysis as $sheet) {
            if ($sheet['layout'] === AttendanceSheetAnalyzer::LAYOUT_PER_EMPLOYEE) {
                $perEmployeeGroups[$this->analyzer->headerSignature($sheet['headers'])][] = $sheet;
                continue;
            }
            if ($sheet['layout'] === null) {
                $skipped[] = $sheet;
                continue;
            }
            $units[] = [
                'key' => 'sheet-' . $sheet['index'],
                'layout' => $sheet['layout'],
                'sheets' => [$sheet],
                'headers' => $sheet['headers'],
                'map' => $sheet['map'],
            ];
        }
        foreach ($perEmployeeGroups as $signature => $group) {
            $units[] = [
                'key' => 'group-' . substr(md5($signature), 0, 12),
                'layout' => AttendanceSheetAnalyzer::LAYOUT_PER_EMPLOYEE,
                'sheets' => $group,
                'headers' => $group[0]['headers'],
                'map' => $group[0]['map'],
            ];
        }
        return [$units, $skipped];
    }

    private function validateMapForLayout(string $layout, array $map): ?string
    {
        $hasEmployee = isset($map['employee_code']) || isset($map['employee_name']);
        return match ($layout) {
            AttendanceSheetAnalyzer::LAYOUT_DAILY => (!isset($map['date']) || !$hasEmployee)
                ? 'حدد عمود التاريخ وعمود الموظف (الكود أو الاسم) قبل الاستيراد.' : null,
            AttendanceSheetAnalyzer::LAYOUT_PUNCHES => (!isset($map['punch_at']) || !$hasEmployee)
                ? 'حدد عمود «التاريخ والوقت» وعمود الموظف (الكود أو الاسم) قبل الاستيراد.' : null,
            AttendanceSheetAnalyzer::LAYOUT_PER_EMPLOYEE => (!isset($map['date']) || (!isset($map['check_in']) && !isset($map['check_out'])))
                ? 'حدد عمود التاريخ وعمود الحضور أو الانصراف قبل الاستيراد.' : null,
            default => 'شكل الورقة غير مدعوم.',
        };
    }

    /** Parse the confirmed unit into uniform rows: employee, date, check_in, check_out, status, ref. */
    private function parseUnit(array $unit, array $map, array $sheets, array $options): array
    {
        return match ($unit['layout']) {
            AttendanceSheetAnalyzer::LAYOUT_DAILY => $this->parseDaily($unit, $map, $sheets, $options),
            AttendanceSheetAnalyzer::LAYOUT_PUNCHES => $this->parsePunches($unit, $map, $sheets, $options),
            AttendanceSheetAnalyzer::LAYOUT_PER_EMPLOYEE => $this->parsePerEmployee($unit, $map, $sheets, $options),
            default => [[], []],
        };
    }

    private function dataRows(array $sheet, array $sheets): array
    {
        $rows = $sheets[$sheet['index']]['rows'] ?? [];
        ksort($rows);
        return array_filter($rows, fn ($r, $n) => $n > $sheet['header_row'] && count(array_filter($r, fn ($v) => trim((string) $v) !== '')) >= 1, ARRAY_FILTER_USE_BOTH);
    }

    private function parseDaily(array $unit, array $map, array $sheets, array $options): array
    {
        $rows = [];
        $errors = [];
        foreach ($unit['sheets'] as $sheet) {
            foreach ($this->dataRows($sheet, $sheets) as $line => $row) {
                $ref = "«{$sheet['name']}» / الصف {$line}";
                [$employee, $error] = $this->resolveEmployee($row[$map['employee_code'] ?? -1] ?? null, $row[$map['employee_name'] ?? -1] ?? null);
                if (!$employee) { $errors[] = "$ref: $error"; continue; }
                $date = $this->parseDateStrict($row[$map['date']] ?? null, $options['date_format'], $ambiguous);
                if (!$date) { $errors[] = "$ref: " . ($ambiguous ? 'تاريخ نصي غامض — حدد تنسيق التاريخ من خيارات الورقة.' : 'تاريخ الحضور غير صالح.'); continue; }
                $checkIn = $this->parseTime($row[$map['check_in'] ?? -1] ?? null);
                $checkOut = $this->parseTime($row[$map['check_out'] ?? -1] ?? null);
                if ((filled($row[$map['check_in'] ?? -1] ?? null) && !$checkIn) || (filled($row[$map['check_out'] ?? -1] ?? null) && !$checkOut)) {
                    $errors[] = "$ref: توقيت حضور أو انصراف غير صالح؛ لم يُستورد الصف."; continue;
                }
                $statusCell = trim((string) ($row[$map['status'] ?? -1] ?? ''));
                if (!$checkIn && !$checkOut && $statusCell === '') {
                    $errors[] = "$ref: صف بلا بصمات ولا حالة — تم تخطيه ولن يُسجَّل غيابًا.";
                    continue;
                }
                $status = $this->status($statusCell) ?: (($checkIn || $checkOut) ? 'Present' : 'Absent');
                $rows[] = ['employee' => $employee, 'date' => $date, 'check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $status, 'ref' => $ref];
            }
        }
        return [$rows, array_slice($errors, 0, 25)];
    }

    private function parsePunches(array $unit, array $map, array $sheets, array $options): array
    {
        $punches = [];
        $employees = [];
        $errors = [];
        foreach ($unit['sheets'] as $sheet) {
            foreach ($this->dataRows($sheet, $sheets) as $line => $row) {
                $ref = "«{$sheet['name']}» / الصف {$line}";
                [$employee, $error] = $this->resolveEmployee($row[$map['employee_code'] ?? -1] ?? null, $row[$map['employee_name'] ?? -1] ?? null);
                if (!$employee) { $errors[] = "$ref: $error"; continue; }
                $at = $this->parseDateTimeStrict($row[$map['punch_at']] ?? null, $options['date_format'], $ambiguous);
                if (!$at) { $errors[] = "$ref: " . ($ambiguous ? 'تاريخ نصي غامض — حدد تنسيق التاريخ من خيارات الورقة.' : 'توقيت البصمة غير صالح.'); continue; }
                $employees[$employee->id] = $employee;
                $punches[] = ['key' => (string) $employee->id, 'at' => $at, 'line' => $ref];
            }
        }
        $aggregator = new PunchAggregator();
        $result = $aggregator->aggregate($punches, function (string $key) use ($employees) {
            $employee = $employees[(int) $key];
            return [
                $this->minutes($employee->shift_start ?: $this->settings->start()),
                $this->minutes($employee->shift_end ?: $this->settings->end()),
            ];
        });
        foreach ($result['errors'] as $ref) {
            if (str_ends_with($ref, '|AMBIGUOUS')) {
                $errors[] = substr($ref, 0, -10) . ': البصمة تقع في نطاق ورديتين متداخلتين؛ راجعها يدويًا.';
            } else {
                $errors[] = "$ref: البصمة خارج نطاق أي وردية؛ راجعها يدويًا.";
            }
        }
        $rows = [];
        foreach ($result['days'] as $day) {
            $ref = "«بصمات» {$day['date']}";
            if (($day['punch_count'] ?? 0) > 2) {
                $errors[] = "$ref: اليوم يحتوي " . $day['punch_count'] . " بصمات؛ سيُحفظ اليوم للمراجعة في الاستثناءات بكل بصماته، دون إنشاء سجل حضور تلقائيًا.";
            }
            $rows[] = ['employee' => $employees[(int) $day['key']], 'date' => $day['date'], 'check_in' => $day['check_in'], 'check_out' => $day['check_out'], 'status' => 'Present', 'ref' => $ref, 'punches' => $day['punches'], 'issue' => count($day['punches']) > 2 ? 'extra' : (count($day['punches']) === 1 ? 'missing' : null)];
        }
        return [$rows, array_slice($errors, 0, 25)];
    }

    private function parsePerEmployee(array $unit, array $map, array $sheets, array $options): array
    {
        $rows = [];
        $errors = [];
        foreach ($unit['sheets'] as $sheet) {
            [$employee, $error] = $this->resolveEmployee($sheet['hint']['code'], $sheet['hint']['name'], allowNameFallback: true);
            if (!$employee) { $errors[] = "«{$sheet['name']}»: $error"; continue; }
            foreach ($this->dataRows($sheet, $sheets) as $line => $row) {
                $ref = "«{$sheet['name']}» / الصف {$line}";
                $date = $this->parseDateStrict($row[$map['date']] ?? null, $options['date_format'], $ambiguous);
                if (!$date) {
                    // Per-employee sheets often repeat metadata under the table; only real failures surface.
                    if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) >= 2) {
                        $errors[] = "$ref: " . ($ambiguous ? 'تاريخ نصي غامض — حدد تنسيق التاريخ من خيارات الورقة.' : 'تاريخ الحضور غير صالح.');
                    }
                    continue;
                }
                $checkIn = $this->parseTime($row[$map['check_in'] ?? -1] ?? null);
                $checkOut = $this->parseTime($row[$map['check_out'] ?? -1] ?? null);
                if ((filled($row[$map['check_in'] ?? -1] ?? null) && !$checkIn) || (filled($row[$map['check_out'] ?? -1] ?? null) && !$checkOut)) {
                    $errors[] = "$ref: توقيت حضور أو انصراف غير صالح؛ لم يُستورد الصف."; continue;
                }
                $statusCell = trim((string) ($row[$map['status'] ?? -1] ?? ''));
                if (!$checkIn && !$checkOut && $statusCell === '') {
                    $errors[] = "$ref: صف بلا بصمات ولا حالة — تم تخطيه ولن يُسجَّل غيابًا.";
                    continue;
                }
                $status = $this->status($statusCell) ?: (($checkIn || $checkOut) ? 'Present' : 'Absent');
                $rows[] = ['employee' => $employee, 'date' => $date, 'check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $status, 'ref' => $ref];
            }
        }
        return [$rows, array_slice($errors, 0, 25)];
    }

    /** Preview card data for one unit: resolution stats, sample rows, overwrite counts, early errors. */
    private function previewUnit(array $unit, array $sheets): array
    {
        $options = $this->normaliseOptions($unit['options'] ?? []);
        [$rows, $errors] = $this->parseUnit($unit, $unit['map'], $sheets, $options);
        $byEmployee = [];
        foreach ($rows as $row) {
            $byEmployee[$row['employee']->id] = $row['employee'];
        }
        $overwrites = $this->overwriteStats($rows);
        $existing = $this->existingAttendanceKeys($rows);
        return [
            'row_count' => count($rows),
            'employee_count' => count($byEmployee),
            'overwrites' => $overwrites['total'],
            'overwrites_manual' => $overwrites['manual'],
            'deferred_existing' => count(array_filter($rows, fn ($row) => ($row['issue'] ?? null) === 'extra' && isset($existing[$row['employee']->id.'|'.$row['date']]))),
            'ambiguous_dates' => $this->unitHasAmbiguousDates($unit, $unit['map'], $sheets),
            'sample' => array_slice(array_map(fn ($row) => [
                'employee' => $row['employee']->full_name,
                'code' => $row['employee']->employee_code,
                'date' => $row['date'],
                'check_in' => ($row['issue'] ?? null) === 'extra' ? 'مراجعة البصمات الزائدة' : ($row['check_in'] ?? '—'),
                'check_out' => ($row['issue'] ?? null) === 'extra' ? 'مراجعة البصمات الزائدة' : ($row['check_out'] ?? '—'),
                'exists' => isset($existing[$row['employee']->id . '|' . $row['date']]),
                'deferred' => ($row['issue'] ?? null) === 'extra',
            ], $rows), 0, 5),
            'errors' => $errors,
        ];
    }

    /** @return array<string, true> set of employee_id|date keys that already have an attendance row. */
    private function existingAttendanceKeys(array $rows): array
    {
        if (!$rows) return [];
        $employeeIds = array_values(array_unique(array_map(fn ($r) => $r['employee']->id, $rows)));
        $dates = array_values(array_unique(array_map(fn ($r) => $r['date'], $rows)));
        $keys = [];
        foreach (Attendance::whereIn('employee_id', $employeeIds)->whereIn('attendance_date', $dates)->get(['employee_id', 'attendance_date']) as $record) {
            $keys[$record->employee_id . '|' . Carbon::parse($record->attendance_date)->toDateString()] = true;
        }
        return $keys;
    }

    /** @return array{total: int, manual: int} existing rows the import would update; manual = not previously imported. */
    private function overwriteStats(array $rows): array
    {
        if (!$rows) return ['total' => 0, 'manual' => 0];
        $employeeIds = array_values(array_unique(array_map(fn ($r) => $r['employee']->id, $rows)));
        $dates = array_values(array_unique(array_map(fn ($r) => $r['date'], $rows)));
        $existing = Attendance::whereIn('employee_id', $employeeIds)->whereIn('attendance_date', $dates)->get(['employee_id', 'attendance_date', 'source']);
        $wanted = [];
        foreach ($rows as $row) {
            if (($row['issue'] ?? null) === 'extra') continue;
            $wanted[$row['employee']->id . '|' . $row['date']] = true;
        }
        $total = 0; $manual = 0;
        foreach ($existing as $record) {
            $key = $record->employee_id . '|' . Carbon::parse($record->attendance_date)->toDateString();
            if (!isset($wanted[$key])) continue;
            $total++;
            if ($record->source !== 'import') $manual++;
        }
        return ['total' => $total, 'manual' => $manual];
    }

    /** True when any date/punch cell holds an ambiguous d/m or m/d text date. */
    private function unitHasAmbiguousDates(array $unit, array $map, array $sheets): bool
    {
        $field = $unit['layout'] === AttendanceSheetAnalyzer::LAYOUT_PUNCHES ? 'punch_at' : 'date';
        if (!isset($map[$field])) return false;
        foreach ($unit['sheets'] as $sheet) {
            foreach ($this->dataRows($sheet, $sheets) as $row) {
                if ($this->isAmbiguousTextDate($row[$map[$field]] ?? null)) return true;
            }
        }
        return false;
    }

    private function isAmbiguousTextDate(mixed $value): bool
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})(\s|$)/', $value, $m)) return false;
        return (int) $m[1] <= 12 && (int) $m[2] <= 12;
    }

    /**
     * Strict date parse: ambiguous text dates need the user-confirmed format;
     * components are validated with checkdate so impossible dates (31/02,
     * 04/25, 13/13) are rejected instead of rolling over into another month.
     * Excel serials and ISO dates parse directly.
     */
    private function parseDateStrict(mixed $value, string $format, ?bool &$ambiguous = null): ?string
    {
        $ambiguous = false;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        if ($format !== '' && preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})/', $raw, $m)) {
            $date = $this->componentsToDate($m[1], $m[2], $m[3], $format);
            return $date?->toDateString();
        }
        if ($this->isAmbiguousTextDate($raw)) { $ambiguous = true; return null; }
        // d/m-shaped but unambiguous (one part > 12): the large part is the day.
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})(\s|$)/', $raw, $m)) {
            $formatGuess = (int) $m[1] > 12 ? 'dmy' : 'mdy';
            $date = $this->componentsToDate($m[1], $m[2], $m[3], $formatGuess);
            return $date?->toDateString();
        }
        return $this->parseCanonicalDateTime($raw)?->toDateString();
    }

    private function parseDateTimeStrict(mixed $value, string $format, ?bool &$ambiguous = null): ?Carbon
    {
        $ambiguous = false;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        if ($format !== '' && preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})(.*)$/', $raw, $m)) {
            $date = $this->componentsToDate($m[1], $m[2], $m[3], $format);
            if (!$date) return null;
            $time = trim($m[4]);
            if ($time !== '' && (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $tm) || (int) $tm[1] > 23 || (int) $tm[2] > 59 || (int) ($tm[3] ?? 0) > 59)) return null;
            try {
                return $time !== '' ? $date->setTimeFromTimeString($time) : $date;
            } catch (\Throwable) {
                return null;
            }
        }
        if ($this->isAmbiguousTextDate($raw)) { $ambiguous = true; return null; }
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})(.*)$/', $raw, $m)) {
            $formatGuess = (int) $m[1] > 12 ? 'dmy' : 'mdy';
            $date = $this->componentsToDate($m[1], $m[2], $m[3], $formatGuess);
            if (!$date) return null;
            $time = trim($m[4]);
            if ($time !== '' && (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $tm) || (int) $tm[1] > 23 || (int) $tm[2] > 59 || (int) ($tm[3] ?? 0) > 59)) return null;
            try {
                return $time !== '' ? $date->setTimeFromTimeString($time) : $date;
            } catch (\Throwable) {
                return null;
            }
        }
        return $this->parseCanonicalDateTime($raw);
    }

    private function parseCanonicalDateTime(string $raw): ?Carbon
    {
        // Only numeric Excel dates or ISO date/time, never relative natural language.
        if (preg_match('/^\d{5}(?:\.\d+)?$/', $raw) && (float) $raw > 20000 && (float) $raw < 80000) {
            return Carbon::create(1899, 12, 30, 0, 0, 0)->addSeconds((int) round((float) $raw * 86400));
        }
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m)) return null;
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) ($m[4] ?? 0) > 23 || (int) ($m[5] ?? 0) > 59 || (int) ($m[6] ?? 0) > 59) return null;
        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    /** Build a date from validated d/m/y or m/d/y components; null when impossible. */
    private function componentsToDate(int|string $first, int|string $second, int|string $year, string $format): ?Carbon
    {
        $year = (int) $year;
        if ($year < 100) $year += 2000;
        [$day, $month] = $format === 'mdy' ? [(int) $second, (int) $first] : [(int) $first, (int) $second];
        if (!checkdate($month, $day, $year)) return null;
        return Carbon::create($year, $month, $day, 0, 0, 0);
    }

    /**
     * Identity is the employee code — the user's confirmed basis for punch
     * imports. The name is review context only, never a lookup key: rows
     * without a code are rejected for clarification, not resolved by name.
     *
     * @return array{0: ?Employee, 1: ?string}
     */
    private function resolveEmployee(mixed $code, mixed $name, bool $allowNameFallback = false): array
    {
        $code = filled($code) ? trim((string) $code) : null;
        $name = filled($name) ? trim((string) $name) : null;
        if (!$code) {
            return [null, 'كود الموظف مطلوب؛ المطابقة بالكود فقط والاسم للمراجعة.'];
        }
        $employee = Employee::where('employee_code', $code)->first();
        if (!$employee) {
            return [null, "كود الموظف «{$code}» غير موجود في النظام."];
        }
        if ($name && !$allowNameFallback && $this->normalisePersonName($employee->full_name) !== $this->normalisePersonName($name)) {
            return [null, "كود الموظف «{$code}» لا يطابق اسم الموظف الموجود في النظام («{$employee->full_name}»)."];
        }
        return [$employee, null];
    }

    private function fieldLabels(): array
    {
        return [
            'employee_code' => 'كود الموظف', 'employee_name' => 'اسم الموظف', 'date' => 'التاريخ',
            'punch_at' => 'التاريخ والوقت (بصمات خام)', 'check_in' => 'الحضور / أول بصمة', 'check_out' => 'الانصراف / آخر بصمة', 'status' => 'الحالة',
        ];
    }

    public function layoutLabel(string $layout): string
    {
        return match ($layout) {
            AttendanceSheetAnalyzer::LAYOUT_DAILY => 'سجلات يومية (صف لكل موظف ويوم)',
            AttendanceSheetAnalyzer::LAYOUT_PUNCHES => 'بصمات خام (صف لكل بصمة؛ تُجمَّع حسب الوردية)',
            AttendanceSheetAnalyzer::LAYOUT_PER_EMPLOYEE => 'ورقة لكل موظف (الموظف من بيانات الورقة)',
            default => $layout,
        };
    }

    private function minutes(string $time): int { $c = Carbon::parse($time); return $c->hour * 60 + $c->minute; }
    private function normalisePersonName(mixed $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        return mb_strtolower((string) $value);
    }

    private function parseTime(mixed $value): ?string {
        $raw=trim((string)$value); if ($raw==='') return null;
        if (is_numeric($raw) && (float)$raw>=0 && (float)$raw<1) { $seconds=(int)round((float)$raw*86400); return sprintf('%02d:%02d',intdiv($seconds,3600)%24,intdiv($seconds%3600,60)); }
        if (preg_match('/^(?:\d{4}-\d{2}-\d{2}[ T])?(\d{1,2}):(\d{2})(?::(\d{2}))?$/',$raw,$m) && (int)$m[1]<24 && (int)$m[2]<60 && (int)($m[3]??0)<60) return sprintf('%02d:%02d',$m[1],$m[2]);
        return null;
    }
    private function status(mixed $value): string { $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); return match (true) { in_array($key, ['متأخر', 'late'], true) => 'Late', in_array($key, ['غائب', 'absent'], true) => 'Absent', in_array($key, ['إجازة', 'leave'], true) => 'Leave', in_array($key, ['حاضر', 'present'], true) => 'Present', default => trim((string) $value) }; }
}
