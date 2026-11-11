<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Services\HrOperationalSettingsService;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;

/** Imports the leave and penalty sheets found in an HR workbook. */
class WorkbookLeavePenaltyImporter
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly HrOperationalSettingsService $settings,
    ) {}

    public const LEAVE_FIELDS = ['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'leave_type' => 'نوع الإجازة', 'start' => 'من تاريخ', 'end' => 'إلى تاريخ', 'days' => 'عدد الأيام', 'status' => 'حالة القرار', 'reason' => 'ملاحظات'];
    public const PENALTY_FIELDS = ['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'penalty_type' => 'نوع الجزاء', 'date' => 'تاريخ القرار', 'deduction' => 'أيام الخصم', 'status' => 'حالة القرار', 'reason' => 'ملاحظات'];

    /** Detected header row + field map for one sheet, exposed for the review UI. */
    public function detectConfig(array $rows): ?array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if ((str_contains($key, 'كودالموظف') || $this->hasBareCodeCell($row)) && (str_contains($key, 'نوعالإجازة') || str_contains($key, 'نوعالجزاء'))) {
                return ['header_row' => (int) $number, 'headers' => array_map(static fn ($v) => (string) $v, $row), 'map' => $this->mapHeaders($row)];
            }
        }

        return null;
    }

    public function import(string $path, string $extension, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns, bool $allowLeaves = true, bool $allowPenalties = true, ?array $onlySheets = null, ?array $configs = null, array $penaltyFixes = []): array
    {
        $result = ['leaves' => ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []], 'penalties' => ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []]];
        foreach ($this->reader->readAll($path, $extension) as $sheet) {
            if ($onlySheets !== null && !in_array((string) ($sheet['name'] ?? ''), $onlySheets, true)) continue;
            $config = $configs[$sheet['name']] ?? null;
            if ($config !== null) {
                $headerRow = (int) ($config['header_row'] ?? -1);
                $headers = $sheet['rows'][$headerRow] ?? [];
                $rows = array_filter($sheet['rows'] ?? [], fn ($unused, $line) => $line > $headerRow, ARRAY_FILTER_USE_BOTH);
                $map = array_map('intval', $config['map'] ?? []);
                // The review's confirmed type decides the branch, not the aliases.
                $forced = (string) ($config['domain'] ?? '');
                if ($forced === 'leaves' && $allowLeaves) {
                    $this->importLeaves($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['leaves'], $headers);
                } elseif ($forced === 'penalties' && $allowPenalties) {
                    $this->importPenalties($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['penalties'], $headers, $penaltyFixes);
                }
                continue;
            }
            [$headers, $rows] = $this->splitHeader($sheet['rows'] ?? []);
            $map = $this->mapHeaders($headers);
            if ($allowLeaves && isset($map['leave_type'], $map['start'], $map['end'])) {
                $this->importLeaves($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['leaves'], $headers);
            } elseif ($allowPenalties && isset($map['penalty_type'], $map['date'], $map['deduction'])) {
                $this->importPenalties($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['penalties'], $headers, $penaltyFixes);
            }
        }
        return $result;
    }

    /** A standalone «الكود»/«كود» header cell also marks the employee-code column. */
    private function hasBareCodeCell(array $row): bool
    {
        foreach ($row as $value) {
            if (in_array($this->normalise($value), ['الكود', 'كود'], true)) return true;
        }
        return false;
    }

    private function importLeaves(string $sheet, array $rows, array $map, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, array &$result, array $headers = []): void
    {
        foreach ($rows as $line => $row) {
            try {
                $employee = $this->employee($row[$map['code'] ?? -1] ?? null, $row[$map['name'] ?? -1] ?? null);
                if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
                $start = $this->date($row[$map['start']] ?? null); $end = $this->date($row[$map['end']] ?? null);
                if (!$start || !$end || $end < $start) throw new \RuntimeException('فترة الإجازة غير صالحة.');
                $guard->ensureRangeOpen($start, $end);
                $typeName = trim((string) ($row[$map['leave_type']] ?? ''));
                $type = LeaveType::where('is_active', true)->where('name', $typeName)->first() ?: LeaveType::where('is_active', true)->where('name', 'like', '%'.$typeName.'%')->first();
                if (!$type) throw new \RuntimeException('نوع الإجازة غير موجود.');
                $status = $this->status($row[$map['status'] ?? -1] ?? '');
                $days = is_numeric($row[$map['days'] ?? -1] ?? null) ? (float) $row[$map['days']] : $this->workingDays($start, $end);
                $exists = Leave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereDate('start_date', $start)->whereDate('end_date', $end)->exists();
                if ($exists) throw new \RuntimeException('سجل الإجازة موجود بالفعل.');
                $leave = new Leave(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'reason' => $row[$map['reason'] ?? -1] ?? null, 'status' => $status, 'approved_by' => $status === 'Approved' ? auth()->id() : null, 'approved_at' => $status === 'Approved' ? now() : null]);
                if ($status === 'Approved') {
                    $overlap = Leave::effective()->where('employee_id', $employee->id)->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
                    if ($overlap) throw new \RuntimeException('يوجد طلب إجازة متداخل مع هذه المدة.');
                    app(\App\Services\LeaveBalanceService::class)->validateForApproval($leave);
                }
                $leave->save();
                app(\App\Services\LeaveBalanceService::class)->sync($employee, Carbon::parse($start));
                app(\App\Services\EmployeeLeaveStatusService::class)->sync();
                $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id); $result['imported']++;
            } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet} / الصف {$line}: {$exception->getMessage()}"; if (count($result['failures']) < 100) $result['failures'][] = ['sheet' => $sheet, 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers ?? []), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => [$exception->getMessage()]]; }
        }
    }

    private function importPenalties(string $sheet, array $rows, array $map, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, array &$result, array $headers = [], array $penaltyFixes = []): void
    {
        foreach ($rows as $line => $row) {
            try {
                // A row the user explicitly excluded in the review is not imported
                // and not a failure: it is recorded as a deliberate dismissal.
                if (($penaltyFixes[$sheet][$line] ?? null) === '__exclude__') {
                    $result['excluded'] = ($result['excluded'] ?? 0) + 1;
                    $result['excluded_rows'][] = ['sheet' => $sheet, 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers ?? []), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => ['استبعده المستخدم أثناء المراجعة قبل الحفظ.']];
                    continue;
                }
                $employee = $this->employee($row[$map['code'] ?? -1] ?? null, $row[$map['name'] ?? -1] ?? null);
                if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
                $date = $this->date($row[$map['date']] ?? null); if (!$date) throw new \RuntimeException('تاريخ الجزاء غير صالح.');
                $guard->ensureOpen($date);
                $typeName = trim((string) ($row[$map['penalty_type']] ?? ''));
                $fixedType = $penaltyFixes[$sheet][$line] ?? null;
                if (is_string($fixedType) && trim($fixedType) !== '') $typeName = trim($fixedType);
                // A missing type must be rejected for review, never matched by wildcard.
                if ($typeName === '') throw new \RuntimeException('نوع الجزاء مطلوب.');
                $type = PenaltyType::where('name', $typeName)->first() ?: PenaltyType::where('name', 'like', '%'.$typeName.'%')->first();
                if (!$type) throw new \RuntimeException('نوع الجزاء غير موجود.');
                $days = (float) ($row[$map['deduction']] ?? 0);
                $exists = Penalty::where('employee_id', $employee->id)->whereDate('penalty_date', $date)->where('penalty_type_id', $type->id)->exists();
                if ($exists) throw new \RuntimeException('سجل الجزاء موجود بالفعل.');
                $status = $this->status($row[$map['status'] ?? -1] ?? '');
                Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => $date, 'title' => $type->name, 'reason' => $row[$map['reason'] ?? -1] ?? $type->name, 'action' => $row[$map['reason'] ?? -1] ?? $type->name, 'amount' => 0, 'deduction_days' => $days, 'status' => $status, 'issued_by' => auth()->id()]);
                $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id); $result['imported']++;
            } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet} / الصف {$line}: {$exception->getMessage()}"; if (count($result['failures']) < 100) $result['failures'][] = ['sheet' => $sheet, 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers ?? []), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => [$exception->getMessage()]]; }
        }
    }

    private function splitHeader(array $rows): array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if ((str_contains($key, 'كودالموظف') || $this->hasBareCodeCell($row)) && (str_contains($key, 'نوعالإجازة') || str_contains($key, 'نوعالجزاء'))) return [$row, array_filter($rows, fn ($unused, $line) => $line > $number, ARRAY_FILTER_USE_BOTH)];
        }
        return [[], []];
    }

    private function mapHeaders(array $headers): array
    {
        $aliases = ['code' => ['كود الموظف', 'الكود', 'كود', 'employee code', 'code'], 'name' => ['اسم الموظف', 'employee name', 'name'], 'leave_type' => ['نوع الإجازة', 'leave type', 'تصنيف الإجازة', 'vacation type', 'leave category'], 'start' => ['من تاريخ', 'start date', 'بداية الإجازة', 'تاريخ بداية الإجازة', 'إجازة من', 'leave start date', 'from date', 'vacation from'], 'end' => ['إلى تاريخ', 'end date', 'نهاية الإجازة', 'تاريخ نهاية الإجازة', 'إجازة إلى', 'leave end date', 'to date', 'vacation to'], 'days' => ['عدد الأيام', 'days', 'عدد أيام الإجازة', 'أيام الإجازة', 'مدة الإجازة', 'leave days', 'number of leave days', 'vacation days', 'leave duration'], 'status' => ['حالة الطلب', 'حالة القرار', 'status', 'حالة الإجازة', 'موقف الطلب', 'leave status', 'request status'], 'reason' => ['ملاحظات', 'السبب', 'reason', 'سبب الإجازة', 'سبب الطلب', 'leave reason', 'request reason', 'وصف المخالفة', 'وصف الجزاء'], 'penalty_type' => ['نوع الجزاء', 'penalty type', 'الجزاء', 'الإجراء التأديبي', 'disciplinary action', 'sanction type'], 'date' => ['تاريخ القرار', 'التاريخ', 'penalty date', 'decision date'], 'deduction' => ['أيام الخصم المطبقة', 'تعديل يدوي لأيام الخصم', 'أيام الخصم', 'deduction days', 'أيام الجزاء', 'عدد أيام الجزاء', 'penalty days', 'suspension days']];
        $map = []; foreach ($headers as $index => $header) { $key = $this->normalise($header); foreach ($aliases as $field => $values) if (in_array($key, array_map(fn ($value) => $this->normalise($value), $values), true)) { $map[$field] = $index; break; } } return $map;
    }

    private function employee(mixed $code, mixed $name): ?Employee { if (filled($code)) { $code = trim((string) $code); $employee = Employee::where('employee_code', $code)->first(); if (!$employee) { throw new \RuntimeException('كود الموظف «' . $code . '» غير موجود في الشركة.'); } if (filled($name) && $this->person($employee->full_name) !== $this->person($name)) { throw new \RuntimeException('كود الموظف لا يطابق الاسم.'); } return $employee; } if (!filled($name)) { return null; } $matches = Employee::where('full_name', trim((string) $name))->get(); if ($matches->count() > 1) { throw new \RuntimeException('الاسم يطابق أكثر من موظف؛ حدّد كود الموظف بدلًا من التخمين.'); } return $matches->first(); }
    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); }
    private function person(mixed $value): string { return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value))); }
    private function date(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function status(mixed $value): string { $key = $this->normalise($value); return str_contains($key, 'رفض') || $key === 'rejected' ? 'Rejected' : 'Approved'; }
    private function workingDays(string $start, string $end): float { return (float) (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1); }
}
