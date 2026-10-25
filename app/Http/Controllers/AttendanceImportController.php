<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\HrOperationalSettingsService;
use App\Services\Imports\SpreadsheetReader;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly HrOperationalSettingsService $settings,
    ) {}

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt']]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');
        try {
            $sheets = $this->reader->readAll($file->getRealPath(), $extension === 'txt' ? 'csv' : $extension);
        } catch (\Throwable $exception) {
            return back()->withErrors(['file' => 'تعذر قراءة الملف: ' . $exception->getMessage()]);
        }
        // Normalize all attendance worksheets into one common schema. Sheets
        // that do not contain attendance headers are ignored (for example
        // employee, leave or advance sheets in the same workbook).
        $mergedRows = [1 => ['employee_code', 'employee_name', 'date', 'check_in', 'check_out', 'status']];
        $nextRow = 2;
        foreach ($sheets as $sheet) {
            $sheetRows = $sheet['rows'] ?? [];
            if (!$sheetRows) continue;
            [$sheetHeaders, $sheetData] = $this->splitHeader($sheetRows);
            $sheetMap = $this->mapHeaders($sheetHeaders);
            if (!isset($sheetMap['date']) || (!isset($sheetMap['employee_code']) && !isset($sheetMap['employee_name']))) continue;
            foreach ($sheetData as $sheetRow) {
                $mergedRows[$nextRow++] = [
                    $sheetRow[$sheetMap['employee_code'] ?? -1] ?? '',
                    $sheetRow[$sheetMap['employee_name'] ?? -1] ?? '',
                    $sheetRow[$sheetMap['date']] ?? '',
                    $sheetRow[$sheetMap['check_in'] ?? -1] ?? '',
                    $sheetRow[$sheetMap['check_out'] ?? -1] ?? '',
                    $sheetRow[$sheetMap['status'] ?? -1] ?? '',
                ];
            }
        }
        $rows = count($mergedRows) > 1 ? $mergedRows : [];
        if (!$rows) return back()->withErrors(['file' => 'الملف لا يحتوي على سجلات.']);
        [$headers, $data] = $this->splitHeader($rows);
        $map = $this->mapHeaders($headers);
        if (!isset($map['date']) || (!isset($map['employee_code']) && !isset($map['employee_name']))) {
            return back()->withErrors(['file' => 'يجب أن يحتوي الملف على كود الموظف أو اسمه، بالإضافة إلى تاريخ الحضور.']);
        }
        $imported = 0; $skipped = 0; $errors = []; $affectedEmployeeIds = [];
        foreach ($data as $rowNumber => $row) {
            try {
                $employee = $this->resolveEmployee($row[$map['employee_code'] ?? -1] ?? null, $row[$map['employee_name'] ?? -1] ?? null);
                if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم غير محدد بشكل فريد.');
                $date = $this->parseDate($row[$map['date']] ?? null);
                if (!$date) throw new \RuntimeException('تاريخ الحضور غير صالح.');
                $periodGuard->ensureOpen($date);
                $checkIn = $this->parseTime($row[$map['check_in'] ?? -1] ?? null);
                $checkOut = $this->parseTime($row[$map['check_out'] ?? -1] ?? null);
                $status = trim((string) ($row[$map['status'] ?? -1] ?? '')) ?: (($checkIn || $checkOut) ? 'Present' : 'Absent');
                $late = $this->lateMinutes($checkIn, $employee->shift_start ?: $this->settings->start());
                $attendance = Attendance::updateOrCreate(
                    ['employee_id' => $employee->id, 'attendance_date' => $date],
                    ['check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $late > 0 ? 'Late' : $status, 'late_minutes' => $late, 'early_leave_minutes' => $this->earlyLeave($checkOut, $employee->shift_end ?: $this->settings->end()), 'worked_hours' => $this->workedHours($checkIn, $checkOut), 'source' => 'import', 'approval_status' => 'Approved', 'approved_by' => null, 'approved_at' => null]
                );
                $affectedEmployeeIds[] = (int) $employee->id;
                $imported++;
            } catch (\Throwable $exception) {
                $skipped++;
                if (count($errors) < 25) $errors[] = "الصف {$rowNumber}: {$exception->getMessage()}";
            }
        }
        foreach (array_unique($affectedEmployeeIds) as $employeeId) {
            $payrollRuns->refreshEmployeeInOpenDrafts($employeeId);
        }
        ActivityLog::record('attendance_import', 'Attendance records imported.', null, null, ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors], 'attendance');
        $message = "تم استيراد {$imported} سجل حضور" . ($skipped ? " وتخطي {$skipped} سجل." : '.');
        return back()->with('toast', $message)->with('attendance_import_errors', $errors);
    }

    private function splitHeader(array $rows): array
    {
        foreach ($rows as $number => $row) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) < 2) continue;
            return [$row, array_filter($rows, fn ($unused, $key) => $key > $number, ARRAY_FILTER_USE_BOTH)];
        }
        return [[], []];
    }

    private function mapHeaders(array $headers): array
    {
        $aliases = [
            'employee_code' => ['employee code', 'employee_code', 'code', 'كود الموظف', 'كود'],
            'employee_name' => ['employee name', 'employee_name', 'name', 'اسم الموظف', 'الموظف'],
            'date' => ['date', 'attendance date', 'attendance_date', 'التاريخ', 'تاريخ الحضور'],
            'check_in' => ['check in', 'check_in', 'in', 'الحضور', 'وقت الحضور'],
            'check_out' => ['check out', 'check_out', 'out', 'الانصراف', 'وقت الانصراف'],
            'status' => ['status', 'الحالة'],
        ];
        $map = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalise($header);
            foreach ($aliases as $field => $values) if (in_array($key, array_map(fn ($value) => $this->normalise($value), $values), true)) { $map[$field] = $index; break; }
        }
        return $map;
    }

    private function resolveEmployee(mixed $code, mixed $name): ?Employee
    {
        if (filled($code)) {
            $employee = Employee::where('employee_code', trim((string) $code))->first();
            if ($employee) {
                if (filled($name) && $this->normalisePersonName($employee->full_name) !== $this->normalisePersonName($name)) {
                    throw new \RuntimeException('كود الموظف لا يطابق اسم الموظف الموجود في النظام.');
                }
                return $employee;
            }
        }
        if (!filled($name)) return null;
        $matches = Employee::where('full_name', trim((string) $name))->limit(2)->get();
        if ($matches->count() > 1) throw new \RuntimeException('اسم الموظف غير فريد؛ استخدم كود الموظف بدلاً منه.');
        return $matches->first();
    }

    private function normalisePersonName(mixed $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        return mb_strtolower((string) $value);
    }

    private function parseDate(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function parseTime(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->format('H:i') : null; } catch (\Throwable) { return null; } }
    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); }
    private function lateMinutes(?string $checkIn, string $start): int { if (!$checkIn) return 0; return max(0, Carbon::parse($checkIn)->hour * 60 + Carbon::parse($checkIn)->minute - (Carbon::parse($start)->hour * 60 + Carbon::parse($start)->minute + $this->settings->grace())); }
    private function earlyLeave(?string $checkOut, string $end): int { if (!$checkOut) return 0; return max(0, Carbon::parse($end)->hour * 60 + Carbon::parse($end)->minute - (Carbon::parse($checkOut)->hour * 60 + Carbon::parse($checkOut)->minute)); }
    private function workedHours(?string $in, ?string $out): ?float { if (!$in || !$out) return null; $start = Carbon::parse($in)->hour * 60 + Carbon::parse($in)->minute; $end = Carbon::parse($out)->hour * 60 + Carbon::parse($out)->minute; return round((($end - $start + 1440) % 1440) / 60, 2); }
}
