<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    public function policy(string $key, string $default = ''): string
    {
        return (string) (DB::table('leave_policy_settings')->where('setting_key', $key)->value('setting_value') ?? $default);
    }

    public function entitlement(Employee $employee, int $year): float
    {
        $hire = $employee->hire_date ? Carbon::parse($employee->hire_date) : Carbon::create($year, 1, 1);
        $age = $employee->birth_date ? Carbon::parse($employee->birth_date)->age : 0;
        $category = (string) ($employee->leave_entitlement_category ?? 'normal');
        if ($category === 'disabled') return (float) $this->policy('annual_disability_days', '45');
        if ($category === 'hazardous') $extra = (float) $this->policy('annual_hazardous_extra_days', '7'); else $extra = 0;
        $serviceYears = $hire->diffInYears(Carbon::create($year, 12, 31));
        if ($serviceYears >= (int) $this->policy('annual_long_service_years', '10') || $age >= (int) $this->policy('annual_age_threshold', '50')) $annual = (float) $this->policy('annual_long_service', '30');
        elseif ($serviceYears >= 1) $annual = (float) $this->policy('annual_second_year', '21');
        else $annual = (float) $this->policy('annual_first_year', '15');
        if ($hire->year === $year && filter_var($this->policy('annual_prorate_first_year', '1'), FILTER_VALIDATE_BOOLEAN)) {
            $annual = round($annual * max(0, 12 - $hire->month + 1) / 12, 2);
        }
        return $annual + $extra;
    }

    public function balance(Employee $employee, int $year): array
    {
        $annual = $employee->manual_annual_balance !== null ? (float) $employee->manual_annual_balance : $this->entitlement($employee, $year);
        $used = $this->approvedDays($employee, $year, fn (LeaveType $type) => (bool) $type->deducts_annual_balance);
        $casual = $this->approvedDays($employee, $year, fn (LeaveType $type) => $type->policy_code === 'casual');
        $advance = max(0, $used - $annual);
        DB::table('leave_balances')->updateOrInsert(['employee_id' => $employee->id, 'leave_year' => $year], [
            'annual_entitlement' => $annual, 'annual_used' => $used, 'casual_used' => $casual, 'advance_used' => $advance, 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['annual_entitlement' => $annual, 'annual_used' => $used, 'casual_used' => $casual, 'advance_used' => $advance, 'remaining' => max(0, $annual - $used)];
    }

    public function validateForApproval(Leave $leave, ?int $excludeId = null): void
    {
        $type = $leave->leaveType;
        $employee = $leave->employee;
        $start = Carbon::parse($leave->start_date);
        $days = (float) $leave->days;
        if (!$type || !$employee) return;
        if ($type->max_days_per_request !== null && $days > (float) $type->max_days_per_request) $this->fail('days', 'تجاوز عدد الأيام الحد المسموح لهذا النوع في الطلب الواحد.');
        $gender = match (mb_strtolower((string) $employee->gender)) { 'female', 'أنثى', 'انثى' => 'female', 'male', 'ذكر' => 'male', default => null };
        if ($type->eligibility_gender && $gender !== $type->eligibility_gender) $this->fail('employee_id', 'نوع الإجازة غير متاح لهذا الموظف.');
        if ($type->min_employees && Employee::count() < (int) $type->min_employees) $this->fail('leave_type_id', 'هذا النوع متاح فقط للمنشآت التي تحقق الحد الأدنى من عدد العاملين.');
        if ($type->min_service_months && !in_array($type->policy_code, ['annual', 'casual'], true) && $employee->hire_date && Carbon::parse($employee->hire_date)->addMonths((int) $type->min_service_months)->gt($start)) $this->fail('start_date', 'لم تكتمل مدة الخدمة المطلوبة لهذا النوع من الإجازة.');
        if ($type->max_occurrences_service && $this->occurrences($employee, $type, $excludeId) >= (int) $type->max_occurrences_service) $this->fail('leave_type_id', 'تم استنفاد عدد مرات هذا النوع طوال مدة الخدمة.');
        if (in_array($type->policy_code, ['annual', 'casual'], true) && $employee->hire_date && Carbon::parse($employee->hire_date)->addMonths((int) $this->policy('annual_wait_months', '6'))->gt($start)) {
            if (!$leave->is_early_grant || !filter_var($this->policy('allow_early_leave', '1'), FILTER_VALIDATE_BOOLEAN)) $this->fail('start_date', 'لا يستحق الموظف الإجازة قبل إتمام مدة الستة أشهر إلا بمنح استثنائي معتمد.');
        }
        if ($type->policy_code === 'casual') {
            $used = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => $item->policy_code === 'casual', $excludeId);
            if ($used + $days > (float) $this->policy('casual_max_days_year', '7')) $this->fail('days', 'تجاوز الطلب الحد السنوي للإجازة العارضة.');
        }
        if ($type->deducts_annual_balance) {
            $balance = $this->balance($employee, $start->year);
            $used = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => (bool) $item->deducts_annual_balance, $excludeId);
            if ($used + $days > $balance['annual_entitlement'] && !$leave->is_early_grant) $this->fail('days', 'لا يوجد رصيد كافٍ لاعتماد هذه الإجازة.');
        }
    }

    public function sync(Employee $employee, ?int $year = null): void { $this->balance($employee, $year ?? now()->year); }

    private function occurrences(Employee $employee, LeaveType $type, ?int $excludeId): int
    {
        $query = Leave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'Approved');
        if ($excludeId) $query->where('id', '!=', $excludeId);
        return $query->count();
    }

    private function approvedDays(Employee $employee, int $year, callable $filter, ?int $excludeId = null): float
    {
        $query = Leave::with('leaveType')->where('employee_id', $employee->id)->where('status', 'Approved')->whereYear('start_date', $year);
        if ($excludeId) $query->where('id', '!=', $excludeId);
        return (float) $query->get()->filter(fn (Leave $leave) => $leave->leaveType && $filter($leave->leaveType))->sum('days');
    }

    private function fail(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
