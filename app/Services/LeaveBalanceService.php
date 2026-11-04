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
    public function balancesFor(Collection $employees, Carbon|int $referenceDate): Collection
    {
        if ($employees->isEmpty()) return collect();

        $referenceDate = $this->referenceDate($referenceDate);
        $ids = $employees->pluck('id')->values();
        $cycles = $employees->mapWithKeys(fn (Employee $employee) => [$employee->id => $this->leaveCycle($employee, $referenceDate)]);
        $cycleStart = $cycles->min(fn (array $cycle) => $cycle['start']->timestamp);
        $cycleEnd = $cycles->max(fn (array $cycle) => $cycle['end']->timestamp);
        $leaves = Leave::with('leaveType')
            ->whereIn('employee_id', $ids)
            ->effective()
            ->whereDate('start_date', '<=', Carbon::createFromTimestamp($cycleEnd)->toDateString())
            ->whereDate('end_date', '>=', Carbon::createFromTimestamp($cycleStart)->toDateString())
            ->get()
            ->groupBy('employee_id');
        $rows = [];
        $now = now();
        foreach ($employees as $employee) {
            $cycle = $cycles->get($employee->id);
            $annual = $employee->manual_annual_balance !== null
                ? (float) $employee->manual_annual_balance
                : $this->entitlement($employee, $cycle['start']);
            $employeeLeaves = $leaves->get($employee->id, collect());
            $used = (float) $employeeLeaves
                ->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance)
                ->sum(fn (Leave $leave) => $this->daysWithinCycle($leave, $cycle));
            $casual = (float) $employeeLeaves
                ->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === 'casual')
                ->sum(fn (Leave $leave) => $this->daysWithinCycle($leave, $cycle));
            $advance = max(0, $used - $annual);
            $rows[] = [
                'employee_id' => $employee->id,
                // The cycle is keyed by the year in which this employee's
                // current leave anniversary period began.
                'leave_year' => $cycle['start']->year,
                'annual_entitlement' => $annual,
                'annual_used' => $used,
                'annual_balance_remaining' => max(0, $annual - $used),
                'casual_used' => $casual,
                'advance_used' => $advance,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // Keep the persisted balance in sync with the current policy/approved
        // leave calculation before it is displayed in the monthly summary.
        DB::table('leave_balances')->upsert($rows, ['employee_id', 'leave_year'], ['annual_entitlement', 'annual_used', 'annual_balance_remaining', 'casual_used', 'advance_used', 'updated_at']);
        return collect($rows)->keyBy('employee_id')->map(fn (array $row) => [
            'annual_entitlement' => (float) $row['annual_entitlement'],
            'annual_used' => (float) $row['annual_used'],
            'annual_balance_remaining' => (float) $row['annual_balance_remaining'],
            'casual_used' => (float) $row['casual_used'],
            'advance_used' => (float) $row['advance_used'],
            'remaining' => (float) $row['annual_balance_remaining'],
        ]);
    }

    public function entitlement(Employee $employee, Carbon|int $referenceDate): float
    {
        $referenceDate = $this->referenceDate($referenceDate);
        $hire = $employee->hire_date ? Carbon::parse($employee->hire_date) : $referenceDate->copy()->startOfYear();
        $age = $employee->birth_date ? Carbon::parse($employee->birth_date)->age : 0;
        $category = (string) ($employee->leave_entitlement_category ?? 'normal');
        if ($category === 'disabled') return (float) $this->policy('annual_disability_days', '45');
        if ($category === 'hazardous') $extra = (float) $this->policy('annual_hazardous_extra_days', '7'); else $extra = 0;
        $serviceYears = $hire->diffInYears($referenceDate);
        if ($serviceYears >= (int) $this->policy('annual_long_service_years', '10') || $age >= (int) $this->policy('annual_age_threshold', '50')) {
            $annual = (float) $this->policy('annual_long_service', '30');
        } elseif ($serviceYears >= 1) {
            $annual = (float) $this->policy('annual_second_year', '21');
        } else {
            $annual = (float) $this->policy('annual_first_year', '15');
        }

        return $annual + $extra;
    }

    public function balance(Employee $employee, Carbon|int $referenceDate): array
    {
        $cycle = $this->leaveCycle($employee, $this->referenceDate($referenceDate));
        $annual = $employee->manual_annual_balance !== null ? (float) $employee->manual_annual_balance : $this->entitlement($employee, $cycle['start']);
        $used = $this->approvedDaysWithinCycle($employee, $cycle, fn (LeaveType $type) => (bool) $type->deducts_annual_balance);
        $casual = $this->approvedDaysWithinCycle($employee, $cycle, fn (LeaveType $type) => $type->policy_code === 'casual');
        $advance = max(0, $used - $annual);
        DB::table('leave_balances')->updateOrInsert(['employee_id' => $employee->id, 'leave_year' => $cycle['start']->year], [
            'annual_entitlement' => $annual, 'annual_used' => $used, 'annual_balance_remaining' => max(0, $annual - $used), 'casual_used' => $casual, 'advance_used' => $advance, 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['annual_entitlement' => $annual, 'annual_used' => $used, 'annual_balance_remaining' => max(0, $annual - $used), 'casual_used' => $casual, 'advance_used' => $advance, 'remaining' => max(0, $annual - $used)];
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
            $cycle = $this->leaveCycle($employee, $start);
            $balance = $this->balance($employee, $start);
            $used = $this->approvedDaysWithinCycle($employee, $cycle, fn (LeaveType $item) => (bool) $item->deducts_annual_balance, $excludeId);
            if ($used + $days > $balance['annual_entitlement'] && !$leave->is_early_grant) $this->fail('days', "لا يمكن اعتماد {$type->name}: الرصيد السنوي {$this->number($balance['annual_entitlement'])} يوم، استُخدم {$this->number($used)} أيام، والمتبقي {$this->number(max(0, $balance['annual_entitlement'] - $used))} يوم، بينما الطلب {$this->number($days)} أيام.");
        }
    }

    public function sync(Employee $employee, Carbon|int|null $referenceDate = null): void { $this->balance($employee, $referenceDate ?? now()); }

    private function occurrences(Employee $employee, LeaveType $type, ?int $excludeId): int
    {
        $query = Leave::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->effective();
        if ($excludeId) $query->where('id', '!=', $excludeId);
        return $query->count();
    }

    private function approvedDays(Employee $employee, int $year, callable $filter, ?int $excludeId = null): float
    {
        $query = Leave::with('leaveType')->where('employee_id', $employee->id)->effective()->whereYear('start_date', $year);
        if ($excludeId) $query->where('id', '!=', $excludeId);
        return (float) $query->get()->filter(fn (Leave $leave) => $leave->leaveType && $filter($leave->leaveType))->sum('days');
    }

    /** The annual leave cycle runs from the employee's hire-date anniversary. */
    private function leaveCycle(Employee $employee, Carbon $referenceDate): array
    {
        $referenceDate = $referenceDate->copy()->startOfDay();
        $hire = $employee->hire_date
            ? Carbon::parse($employee->hire_date)->startOfDay()
            : $referenceDate->copy()->startOfYear();
        $anniversary = $hire->copy()->setYear($referenceDate->year);
        $start = $referenceDate->lt($anniversary) ? $anniversary->subYear() : $anniversary;

        return ['start' => $start, 'end' => $start->copy()->addYear()->subDay()];
    }

    private function referenceDate(Carbon|int $referenceDate): Carbon
    {
        return $referenceDate instanceof Carbon
            ? $referenceDate->copy()->startOfDay()
            : Carbon::create((int) $referenceDate, 1, 1)->startOfDay();
    }

    private function approvedDaysWithinCycle(Employee $employee, array $cycle, callable $filter, ?int $excludeId = null): float
    {
        $query = Leave::with('leaveType')
            ->where('employee_id', $employee->id)
            ->effective()
            ->whereDate('start_date', '<=', $cycle['end'])
            ->whereDate('end_date', '>=', $cycle['start']);
        if ($excludeId) $query->where('id', '!=', $excludeId);

        return (float) $query->get()
            ->filter(fn (Leave $leave) => $leave->leaveType && $filter($leave->leaveType))
            ->sum(fn (Leave $leave) => $this->daysWithinCycle($leave, $cycle));
    }

    private function daysWithinCycle(Leave $leave, array $cycle): float
    {
        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();
        $overlapStart = $start->greaterThan($cycle['start']) ? $start : $cycle['start'];
        $overlapEnd = $end->lessThan($cycle['end']) ? $end : $cycle['end'];
        if ($overlapStart->gt($overlapEnd)) return 0.0;

        $fullDays = max(1, $start->diffInDays($end) + 1);
        $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;

        return round((float) $leave->days * ($overlapDays / $fullDays), 2);
    }

    private function fail(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }

    private function number(float|int|string $value): string
    {
        $value = (float) $value;
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
