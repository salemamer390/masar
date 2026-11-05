<?php

namespace App\Services\Imports;

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\HrOperationalSettingsService;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;

/** Imports attendance sheets found in a workbook after employee import. */
class WorkbookAttendanceImporter
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly HrOperationalSettingsService $settings,
    ) {}

    public function import(string $path, string $extension, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns): array
    {
        $sheets = $this->reader->readAll($path, $extension);
        $imported = 0; $skipped = 0; $errors = []; $failures = []; $affected = [];
        foreach ($sheets as $sheet) {
            [$headers, $rows] = $this->splitHeader($sheet['rows'] ?? []);
            $map = $this->mapHeaders($headers);
            if (!isset($map['date']) || (!isset($map['code']) && !isset($map['name']))) continue;
            foreach ($rows as $line => $row) {
                try {
                    $employee = $this->employee($row[$map['code'] ?? -1] ?? null, $row[$map['name'] ?? -1] ?? null);
                    if (!$employee) throw new \RuntimeException('الموظف غير موجود أو بياناته غير مكتملة.');
                    $date = $this->date($row[$map['date']] ?? null);
                    if (!$date) throw new \RuntimeException('تاريخ الحضور غير صالح.');
                    $periodGuard->ensureOpen($date); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($date);
                    $in = $this->time($row[$map['in'] ?? -1] ?? null);
                    $out = $this->time($row[$map['out'] ?? -1] ?? null);
                    $status = $this->status($row[$map['status'] ?? -1] ?? '') ?: (($in || $out) ? 'Present' : 'Absent');
                    $metrics = app(\App\Services\AttendanceMetricsService::class)->metrics($employee, $in, $out, $status);
                    Attendance::updateOrCreate(
                        ['employee_id' => $employee->id, 'attendance_date' => $date],
                        ['check_in' => $in, 'check_out' => $out, ...$metrics, 'source' => 'import', 'approval_status' => 'Approved']
                    );
                    $affected[] = (int) $employee->id;
                    $imported++;
                } catch (\Throwable $exception) {
                    $skipped++;
                    if (count($errors) < 25) $errors[] = "{$sheet['name']} / الصف {$line}: {$exception->getMessage()}";
                    if (count($failures) < 100) $failures[] = ['sheet' => $sheet['name'], 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => [$exception->getMessage()]];
                }
            }
        }
        foreach (array_unique($affected) as $employeeId) $payrollRuns->refreshEmployeeInOpenDrafts($employeeId);
        return compact('imported', 'skipped', 'errors', 'failures');
    }

    private function splitHeader(array $rows): array
    {
        foreach ($rows as $number => $row) {
            $joined = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if (str_contains($joined, 'التاريخ') && (str_contains($joined, 'كودالموظف') || str_contains($joined, 'اسمالموظف'))) {
                return [$row, array_filter($rows, fn ($unused, $key) => $key > $number, ARRAY_FILTER_USE_BOTH)];
            }
        }
        foreach ($rows as $number => $row) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) < 2) continue;
            return [$row, array_filter($rows, fn ($unused, $key) => $key > $number, ARRAY_FILTER_USE_BOTH)];
        }
        return [[], []];
    }

    private function mapHeaders(array $headers): array
    {
        $aliases = [
            'code' => ['employee code', 'employee_code', 'code', 'كود الموظف', 'الكود'],
            'name' => ['employee name', 'employee_name', 'name', 'اسم الموظف', 'الموظف'],
            'date' => ['date', 'attendance date', 'attendance_date', 'التاريخ', 'تاريخ الحضور'],
            'in' => ['check in', 'check_in', 'in', 'الحضور', 'وقت الحضور'],
            'out' => ['check out', 'check_out', 'out', 'الانصراف', 'وقت الانصراف'],
            'status' => ['status', 'الحالة'],
        ];
        $map = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalise($header);
            foreach ($aliases as $field => $values) if (in_array($key, array_map(fn ($value) => $this->normalise($value), $values), true)) { $map[$field] = $index; break; }
        }
        return $map;
    }

    private function employee(mixed $code, mixed $name): ?Employee
    {
        if (filled($code)) {
            $code = trim((string) $code);
            $employee = Employee::where('employee_code', $code)->first();
            // A supplied code is a load-bearing identifier: an unknown code is
            // rejected with an explicit reason, never swapped for a name match.
            if (!$employee) throw new \RuntimeException('كود الموظف «' . $code . '» غير موجود في الشركة.');
            if (filled($name) && $this->person($employee->full_name) !== $this->person($name)) throw new \RuntimeException('كود الموظف لا يطابق الاسم.');
            return $employee;
        }
        if (!filled($name)) return null;
        // A bare name matches only when it identifies exactly one employee of
        // the company (the tenant global scope applies) - duplicates and
        // conflicts are rejected and parked for review, never silently picked.
        $matches = Employee::where('full_name', trim((string) $name))->get();
        if ($matches->count() > 1) throw new \RuntimeException('الاسم يطابق أكثر من موظف؛ حدّد كود الموظف بدلًا من التخمين.');
        return $matches->first();
    }

    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); }
    private function status(mixed $value): string { $key = $this->normalise($value); return match (true) { in_array($key, ['متأخر', 'late'], true) => 'Late', in_array($key, ['غائب', 'absent'], true) => 'Absent', in_array($key, ['إجازة', 'leave'], true) => 'Leave', in_array($key, ['حاضر', 'present'], true) => 'Present', default => trim((string) $value) }; }
    private function person(mixed $value): string { return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value))); }
    private function date(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function time(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->format('H:i') : null; } catch (\Throwable) { return null; } }
    private function late(?string $value, string $start): int { if (!$value) return 0; return max(0, Carbon::parse($value)->diffInMinutes(Carbon::parse($start), false) * -1); }
    private function early(?string $value, string $end): int { if (!$value) return 0; return max(0, Carbon::parse($end)->diffInMinutes(Carbon::parse($value), false)); }
    private function worked(?string $in, ?string $out): ?float { if (!$in || !$out) return null; return round(Carbon::parse($in)->diffInMinutes(Carbon::parse($out)) / 60, 2); }
}
