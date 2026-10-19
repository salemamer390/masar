<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class LeaveBalanceService
{
    /** Policies are identical for all employees in one request. */
    private array $policyCache = [];

    public function policy(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->policyCache)) return $this->policyCache[$key];
        $query = DB::table('leave_policy_settings')->where('setting_key', $key);
        if (auth()->check() && !auth()->user()->isSuperAdmin() && auth()->user()->company_id) $query->where('company_id', auth()->user()->company_id);
        return $this->policyCache[$key] = (string) ($query->value('setting_value') ?? $default);
    }

    /**
     * Calculate balances for a batch of employees with one leave query and one
     * balance upsert, instead of running balance() (and its queries) per row.
     */
    public function balancesFor(Collection $employees, int $year): Collection
    {
        if ($employees->isEmpty()) return collect();

        $ids = $employees->pluck('id')->values();
        $leaves = Leave::with('leaveType')
            ->whereIn('employee_id', $ids)
            ->where('status', 'Approved')
            ->whereYear('start_date', $year)
            ->get()
            ->groupBy('employee_id');
        $rows = [];
        $now = now();
        foreach ($employees as $employee) {
            $annual = $employee->manual_annual_balance !== null
                ? (float) $employee->manual_annual_balance
                : $this->entitlement($employee, $year);
            $employeeLeaves = $leaves->get($employee->id, collect());
            $used = (float) $employeeLeaves
                ->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance)
                ->sum('days');
            $casual = (float) $employeeLeaves
                ->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === 'casual')
                ->sum('days');
            $advance = max(0, $used - $annual);
            $rows[] = [
                'employee_id' => $employee->id,
                'leave_year' => $year,
                'annual_entitlement' => $annual,
                'annual_used' => $used,
                'casual_used' => $casual,
                'advance_used' => $advance,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('leave_balances')->upsert($rows, ['employee_id', 'leave_year'], ['annual_entitlement', 'annual_used', 'casual_used', 'advance_used', 'updated_at']);
        return collect($rows)->keyBy('employee_id')->map(fn (array $row) => [
            'annual_entitlement' => (float) $row['annual_entitlement'],
            'annual_used' => (float) $row['annual_used'],
            'casual_used' => (float) $row['casual_used'],
            'advance_used' => (float) $row['advance_used'],
            'remaining' => max(0, (float) $row['annual_entitlement'] - (float) $row['annual_used']),
        ]);
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
        if ($type->policy_code !== 'casual' && $type->max_days_per_request !== null && $days > (float) $type->max_days_per_request) {
            $this->fail('days', "لا يمكن اعتماد {$type->name}: الطلب {$this->number($days)} أيام، والحد الأقصى للطلب الواحد {$this->number($type->max_days_per_request)} أيام.");
        }
        $gender = match (mb_strtolower((string) $employee->gender)) { 'female', 'أنثى', 'انثى' => 'female', 'male', 'ذكر' => 'male', default => null };
        if ($type->eligibility_gender && $gender !== $type->eligibility_gender) $this->fail('employee_id', "لا يمكن اعتماد {$type->name}: هذا النوع متاح للموظفات فقط.");
        if ($type->min_employees && ($currentEmployees = Employee::count()) < (int) $type->min_employees) $this->fail('leave_type_id', "لا يمكن اعتماد {$type->name}: عدد العاملين الحالي {$currentEmployees}، بينما الحد الأدنى المطلوب {$type->min_employees} عاملًا.");
        if ($type->min_service_months && !in_array($type->policy_code, ['annual', 'casual'], true) && $employee->hire_date && ($eligibleAt = Carbon::parse($employee->hire_date)->addMonths((int) $type->min_service_months))->gt($start)) $this->fail('start_date', "لا يمكن اعتماد {$type->name}: يشترط إتمام {$type->min_service_months} شهر خدمة. يصبح الموظف مستحقًا في {$eligibleAt->format('d/m/Y')}.");
        if ($type->max_occurrences_service && ($usedOccurrences = $this->occurrences($employee, $type, $excludeId)) >= (int) $type->max_occurrences_service) $this->fail('leave_type_id', "لا يمكن اعتماد {$type->name}: استخدم الموظف {$usedOccurrences} من الحد الأقصى {$type->max_occurrences_service} مرات طوال مدة الخدمة.");
        if ($type->max_days_per_year !== null) {
            $usedThisYear = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => $item->id === $type->id, $excludeId);
            if ($usedThisYear + $days > (float) $type->max_days_per_year) {
                $cap = (float) $type->max_days_per_year;
                $this->fail('days', "لا يمكن اعتماد {$type->name}: الحد السنوي {$this->number($cap)} أيام، استُخدم {$this->number($usedThisYear)} أيام، والمتبقي {$this->number(max(0, $cap - $usedThisYear))} يوم، بينما الطلب {$this->number($days)} أيام.");
            }
        }
        if (in_array($type->policy_code, ['annual', 'casual'], true) && $employee->hire_date && Carbon::parse($employee->hire_date)->addMonths((int) $this->policy('annual_wait_months', '6'))->gt($start)) {
            if (!$leave->is_early_grant || !filter_var($this->policy('allow_early_leave', '1'), FILTER_VALIDATE_BOOLEAN)) {
                $eligibleAt = Carbon::parse($employee->hire_date)->addMonths((int) $this->policy('annual_wait_months', '6'));
                $this->fail('start_date', "لا يمكن اعتماد {$type->name}: يلزم إتمام {$this->policy('annual_wait_months', '6')} أشهر خدمة. يصبح الموظف مستحقًا في {$eligibleAt->format('d/m/Y')}، أو يُمنح استثنائيًا بصلاحية الاعتماد.");
            }
        }
        if ($type->policy_code === 'casual') {
            $used = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => $item->policy_code === 'casual', $excludeId);
            $cap = (float) $this->policy('casual_max_days_year', '7');
            if ($used + $days > $cap) $this->fail('days', "لا يمكن اعتماد الإجازة العارضة: الحد السنوي {$this->number($cap)} أيام، استُخدم {$this->number($used)} أيام، والمتبقي {$this->number(max(0, $cap - $used))} يوم، بينما الطلب {$this->number($days)} أيام.");
        }
        if ($type->deducts_annual_balance) {
            $balance = $this->balance($employee, $start->year);
            $used = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => (bool) $item->deducts_annual_balance, $excludeId);
            if ($used + $days > $balance['annual_entitlement'] && !$leave->is_early_grant) $this->fail('days', "لا يمكن اعتماد {$type->name}: الرصيد السنوي {$this->number($balance['annual_entitlement'])} يوم، استُخدم {$this->number($used)} أيام، والمتبقي {$this->number(max(0, $balance['annual_entitlement'] - $used))} يوم، بينما الطلب {$this->number($days)} أيام.");
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

    private function number(float|int|string $value): string
    {
        $value = (float) $value;
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
