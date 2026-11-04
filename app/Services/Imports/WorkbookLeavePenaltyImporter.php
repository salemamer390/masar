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

    public function import(string $path, string $extension, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns, bool $allowLeaves = true, bool $allowPenalties = true): array
    {
        $result = ['leaves' => ['imported' => 0, 'skipped' => 0, 'errors' => []], 'penalties' => ['imported' => 0, 'skipped' => 0, 'errors' => []]];
        foreach ($this->reader->readAll($path, $extension) as $sheet) {
            [$headers, $rows] = $this->splitHeader($sheet['rows'] ?? []);
            $map = $this->mapHeaders($headers);
            if ($allowLeaves && isset($map['leave_type'], $map['start'], $map['end'])) {
                $this->importLeaves($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['leaves']);
            } elseif ($allowPenalties && isset($map['penalty_type'], $map['date'], $map['deduction'])) {
                $this->importPenalties($sheet['name'], $rows, $map, $periodGuard, $payrollRuns, $result['penalties']);
            }
        }
        return $result;
    }

    private function importLeaves(string $sheet, array $rows, array $map, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, array &$result): void
    {
        foreach ($rows as $line => $row) {
            try {
                $employee = $this->employee($row[$map['code'] ?? -1] ?? null, $row[$map['name'] ?? -1] ?? null);
                if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
                $start = $this->date($row[$map['start']] ?? null); $end = $this->date($row[$map['end']] ?? null);
                if (!$start || !$end || $end < $start) throw new \RuntimeException('فترة الإجازة غير صالحة.');
                $guard->ensureRangeOpen($start, $end);
                $typeName = trim((string) ($row[$map['leave_type']] ?? ''));
                $type = LeaveType::where('name', $typeName)->first() ?: LeaveType::where('name', 'like', '%'.$typeName.'%')->first();
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
            } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet} / الصف {$line}: {$exception->getMessage()}"; }
        }
    }

    private function importPenalties(string $sheet, array $rows, array $map, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, array &$result): void
    {
        foreach ($rows as $line => $row) {
            try {
                $employee = $this->employee($row[$map['code'] ?? -1] ?? null, $row[$map['name'] ?? -1] ?? null);
                if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
                $date = $this->date($row[$map['date']] ?? null); if (!$date) throw new \RuntimeException('تاريخ الجزاء غير صالح.');
                $guard->ensureOpen($date);
                $typeName = trim((string) ($row[$map['penalty_type']] ?? ''));
                $type = PenaltyType::where('name', $typeName)->first() ?: PenaltyType::where('name', 'like', '%'.$typeName.'%')->first();
                if (!$type) throw new \RuntimeException('نوع الجزاء غير موجود.');
                $days = (float) ($row[$map['deduction']] ?? 0);
                $exists = Penalty::where('employee_id', $employee->id)->whereDate('penalty_date', $date)->where('penalty_type_id', $type->id)->exists();
                if ($exists) throw new \RuntimeException('سجل الجزاء موجود بالفعل.');
                $status = $this->status($row[$map['status'] ?? -1] ?? '');
                Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => $date, 'title' => $type->name, 'reason' => $row[$map['reason'] ?? -1] ?? $type->name, 'action' => $row[$map['reason'] ?? -1] ?? $type->name, 'amount' => 0, 'deduction_days' => $days, 'status' => $status, 'issued_by' => auth()->id()]);
                $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id); $result['imported']++;
            } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet} / الصف {$line}: {$exception->getMessage()}"; }
        }
    }

    private function splitHeader(array $rows): array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if (str_contains($key, 'كودالموظف') && (str_contains($key, 'نوعالإجازة') || str_contains($key, 'نوعالجزاء'))) return [$row, array_filter($rows, fn ($unused, $line) => $line > $number, ARRAY_FILTER_USE_BOTH)];
        }
        return [[], []];
    }

    private function mapHeaders(array $headers): array
    {
        $aliases = ['code' => ['كود الموظف', 'employee code', 'code'], 'name' => ['اسم الموظف', 'employee name', 'name'], 'leave_type' => ['نوع الإجازة', 'leave type'], 'start' => ['من تاريخ', 'start date'], 'end' => ['إلى تاريخ', 'end date'], 'days' => ['عدد الأيام', 'days'], 'status' => ['حالة الطلب', 'حالة القرار', 'status'], 'reason' => ['ملاحظات', 'السبب', 'reason'], 'penalty_type' => ['نوع الجزاء', 'penalty type'], 'date' => ['تاريخ القرار', 'penalty date'], 'deduction' => ['أيام الخصم المطبقة', 'تعديل يدوي لأيام الخصم', 'أيام الخصم', 'deduction days']];
        $map = []; foreach ($headers as $index => $header) { $key = $this->normalise($header); foreach ($aliases as $field => $values) if (in_array($key, array_map(fn ($value) => $this->normalise($value), $values), true)) { $map[$field] = $index; break; } } return $map;
    }

    private function employee(mixed $code, mixed $name): ?Employee { if (filled($code)) { $employee = Employee::where('employee_code', trim((string) $code))->first(); if ($employee) { if (filled($name) && $this->person($employee->full_name) !== $this->person($name)) throw new \RuntimeException('كود الموظف لا يطابق الاسم.'); return $employee; } } return filled($name) ? Employee::where('full_name', trim((string) $name))->first() : null; }
    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); }
    private function person(mixed $value): string { return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value))); }
    private function date(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function status(mixed $value): string { $key = $this->normalise($value); return str_contains($key, 'رفض') || $key === 'rejected' ? 'Rejected' : 'Approved'; }
    private function workingDays(string $start, string $end): float { return (float) (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1); }
}
