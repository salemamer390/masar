<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class LeaveBalanceService
{
    /** Policies are identical for all employees in one request. */
    private array $policyCache = [];
    /** Screen-confirmed waiting-time exception: waives only the wait, the balance check stays on. */
    private bool $enforceBalanceOnEarlyGrant = false;

    public function enforceBalanceOnEarlyGrant(bool $enforce = true): static
    {
        $this->enforceBalanceOnEarlyGrant = $enforce;
        return $this;
    }

    public function policy(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->policyCache)) return $this->policyCache[$key];
        $query = DB::table('leave_policy_settings')->where('setting_key', $key);
        if (auth()->check() && !auth()->user()->isSuperAdmin() && auth()->user()->company_id) $query->where('company_id', auth()->user()->company_id);
        return $this->policyCache[$key] = (string) ($query->value('setting_value') ?? $default);
    }

    /** Company row first, then the global default row: safe in console runs. */
    private function policyFor(string $key, string $default, ?int $companyId): string
    {
        $cacheKey = $key.':'.($companyId ?? 0);
        if (array_key_exists($cacheKey, $this->policyCache)) return $this->policyCache[$cacheKey];
        if ($companyId) {
            $value = DB::table('leave_policy_settings')->where('setting_key', $key)->where('company_id', $companyId)->value('setting_value');
            if ($value !== null) return $this->policyCache[$cacheKey] = (string) $value;
        }
        return $this->policyCache[$cacheKey] = (string) (DB::table('leave_policy_settings')->where('setting_key', $key)->whereNull('company_id')->value('setting_value') ?? $default);
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
        // Carryover needs every prior cycle too, so the leave window opens at
        // each employee's first cycle (hire date) instead of the current one.
        $cycleStart = $employees->map(fn (Employee $employee) => $this->firstCycleStart($employee, $referenceDate)->timestamp)->min();
        $cycleEnd = $cycles->max(fn (array $cycle) => $cycle['end']->timestamp);
        $leaves = Leave::with('leaveType')
            ->whereIn('employee_id', $ids)
            ->effective()
            ->whereDate('start_date', '<=', Carbon::createFromTimestamp($cycleEnd)->toDateString())
            ->whereDate('end_date', '>=', Carbon::createFromTimestamp($cycleStart)->toDateString())
            ->get()
            ->groupBy('employee_id');
        $openingEntries = DB::table('leave_opening_entries')->whereIn('employee_id', $ids)->get()
            ->groupBy('employee_id')
            ->map(fn ($list) => $list->keyBy('cycle_year'));
        $rows = [];
        $now = now();
        foreach ($employees as $employee) {
            $cycle = $cycles->get($employee->id);
            $annual = $employee->manual_annual_balance !== null
                ? (float) $employee->manual_annual_balance
                : $this->entitlement($employee, $cycle['start']);
            $employeeLeaves = $leaves->get($employee->id, collect());
            $deducting = $employeeLeaves->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance);
            // «Used» stops at the reference date: future approved leave inside
            // the cycle is not consumed yet, and the unused balance of every
            // earlier cycle carries forward without expiring.
            $used = (float) $deducting->sum(fn (Leave $leave) => $this->daysWithinRange($leave, $cycle['start'], $referenceDate->lessThan($cycle['end']) ? $referenceDate : $cycle['end']));
            $allocation = $this->allocate($employee, $deducting, $cycle, $used, $openingEntries->get($employee->id));
            $carried = $allocation['carried'];
            $casual = (float) $employeeLeaves
                ->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === 'casual')
                ->sum(fn (Leave $leave) => $this->daysWithinRange($leave, $cycle['start'], $referenceDate->lessThan($cycle['end']) ? $referenceDate : $cycle['end']));
            $usable = $allocation['usable_capacity'];
            $advance = $allocation['advance'];
            $rows[] = [
                'employee_id' => $employee->id,
                // The cycle is keyed by the year in which this employee's
                // current leave anniversary period began.
                'leave_year' => $cycle['start']->year,
                'annual_entitlement' => $annual,
                'annual_used' => $used,
                'carried_balance' => $carried,
                'annual_balance_remaining' => $allocation['remaining'],
                'casual_used' => $casual,
                'advance_used' => $advance,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // Keep the persisted balance in sync with the current policy/approved
        // leave calculation before it is displayed in the monthly summary.
        DB::table('leave_balances')->upsert($rows, ['employee_id', 'leave_year'], ['annual_entitlement', 'annual_used', 'carried_balance', 'annual_balance_remaining', 'casual_used', 'advance_used', 'updated_at']);
        return collect($rows)->keyBy('employee_id')->map(fn (array $row) => [
            'annual_entitlement' => (float) $row['annual_entitlement'],
            'annual_used' => (float) $row['annual_used'],
            'carried_balance' => (float) $row['carried_balance'],
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
        $companyId = $employee->company_id;
        $category = (string) ($employee->leave_entitlement_category ?? 'normal');
        if ($category === 'disabled') return (float) $this->policyFor('annual_disability_days', '45', $companyId);
        if ($category === 'hazardous') $extra = (float) $this->policyFor('annual_hazardous_extra_days', '7', $companyId); else $extra = 0;
        $serviceYears = $hire->diffInYears($referenceDate);
        if ($serviceYears >= (int) $this->policyFor('annual_long_service_years', '10', $companyId) || $age >= (int) $this->policyFor('annual_age_threshold', '50', $companyId)) {
            $annual = (float) $this->policyFor('annual_long_service', '30', $companyId);
        } elseif ($serviceYears >= 1) {
            $annual = (float) $this->policyFor('annual_second_year', '21', $companyId);
        } else {
            $annual = (float) $this->policyFor('annual_first_year', '15', $companyId);
        }

        return $annual + $extra;
    }

    public function balance(Employee $employee, Carbon|int $referenceDate): array
    {
        $referenceDate = $this->referenceDate($referenceDate);
        $cycle = $this->leaveCycle($employee, $referenceDate);
        $annual = $employee->manual_annual_balance !== null ? (float) $employee->manual_annual_balance : $this->entitlement($employee, $cycle['start']);
        $leaves = Leave::with('leaveType')->where('employee_id', $employee->id)->effective()
            ->whereDate('start_date', '<=', $cycle['end'])
            ->whereDate('end_date', '>=', $this->firstCycleStart($employee, $referenceDate))->get();
        $deducting = $leaves->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance);
        $upper = $referenceDate->lessThan($cycle['end']) ? $referenceDate : $cycle['end'];
        $used = (float) $deducting->sum(fn (Leave $leave) => $this->daysWithinRange($leave, $cycle['start'], $upper));
        $allocation = $this->allocate($employee, $deducting, $cycle, $used, $this->openingEntriesFor($employee));
        $carried = $allocation['carried'];
        $casual = (float) $leaves->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === 'casual')
            ->sum(fn (Leave $leave) => $this->daysWithinRange($leave, $cycle['start'], $upper));
        DB::table('leave_balances')->updateOrInsert(['employee_id' => $employee->id, 'leave_year' => $cycle['start']->year], [
            'annual_entitlement' => $annual, 'annual_used' => $used, 'carried_balance' => $carried, 'annual_balance_remaining' => $allocation['remaining'], 'casual_used' => $casual, 'advance_used' => $allocation['advance'], 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['annual_entitlement' => $annual, 'annual_used' => $used, 'carried_balance' => $carried, 'usable_capacity' => $allocation['usable_capacity'], 'annual_balance_remaining' => $allocation['remaining'], 'casual_used' => $casual, 'advance_used' => $allocation['advance'], 'remaining' => $allocation['remaining']];
    }

    /**
     * Effective limits of a leave type for one company: a company-owned type
     * uses its own columns; a shared type uses the company's own copy when it
     * has saved one, otherwise the shared values.
     *
     * @return array{max_days_per_request:?float,max_days_per_year:?float,min_service_months:?int,max_occurrences_service:?int}
     */
    public function limitsFor(LeaveType $type, ?int $companyId): array
    {
        $row = null;
        if ($companyId && !$type->company_id && Schema::hasTable('leave_type_company_limits')) {
            $row = DB::table('leave_type_company_limits')->where('company_id', $companyId)->where('leave_type_id', $type->id)->first();
        }
        $src = $row ?? $type;
        return [
            'max_days_per_request' => $src->max_days_per_request !== null ? (float) $src->max_days_per_request : null,
            'max_days_per_year' => $src->max_days_per_year !== null ? (float) $src->max_days_per_year : null,
            'min_service_months' => $src->min_service_months !== null ? (int) $src->min_service_months : null,
            'max_occurrences_service' => $src->max_occurrences_service !== null ? (int) $src->max_occurrences_service : null,
        ];
    }

    public function validateForApproval(Leave $leave, ?int $excludeId = null): void
    {
        $type = $leave->leaveType;
        $employee = $leave->employee;
        $start = Carbon::parse($leave->start_date);
        $days = (float) $leave->days;
        if (!$type || !$employee) return;
        $limits = $this->limitsFor($type, $employee->company_id ? (int) $employee->company_id : null);
        if (!in_array($type->policy_code, ['annual', 'casual'], true) && $limits['max_days_per_request'] !== null && $days > $limits['max_days_per_request']) {
            $this->fail('days', "لا يمكن اعتماد {$type->name}: الطلب {$this->number($days)} أيام، والحد الأقصى للطلب الواحد {$this->number($limits['max_days_per_request'])} أيام.");
        }
        $gender = match (mb_strtolower((string) $employee->gender)) { 'female', 'أنثى', 'انثى' => 'female', 'male', 'ذكر' => 'male', default => null };
        if ($type->eligibility_gender && $gender !== $type->eligibility_gender) $this->fail('employee_id', "لا يمكن اعتماد {$type->name}: هذا النوع متاح للموظفات فقط.");
        if ($type->min_employees && ($currentEmployees = Employee::count()) < (int) $type->min_employees) $this->fail('leave_type_id', "لا يمكن اعتماد {$type->name}: عدد العاملين الحالي {$currentEmployees}، بينما الحد الأدنى المطلوب {$type->min_employees} عاملًا.");
        if ($limits['min_service_months'] && !in_array($type->policy_code, ['annual', 'casual'], true) && $employee->hire_date && ($eligibleAt = Carbon::parse($employee->hire_date)->addMonths((int) $limits['min_service_months']))->gt($start)) $this->fail('start_date', "لا يمكن اعتماد {$type->name}: يشترط إتمام {$limits['min_service_months']} شهر خدمة. يصبح الموظف مستحقًا في {$eligibleAt->format('d/m/Y')}.");
        if ($limits['max_occurrences_service'] && ($usedOccurrences = $this->occurrences($employee, $type, $excludeId)) >= (int) $limits['max_occurrences_service']) $this->fail('leave_type_id', "لا يمكن اعتماد {$type->name}: استخدم الموظف {$usedOccurrences} من الحد الأقصى {$limits['max_occurrences_service']} مرات طوال مدة الخدمة.");
        if ($limits['max_days_per_year'] !== null) {
            $usedThisYear = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => $item->id === $type->id, $excludeId);
            if ($usedThisYear + $days > $limits['max_days_per_year']) {
                $cap = $limits['max_days_per_year'];
                $this->fail('days', "لا يمكن اعتماد {$type->name}: الحد السنوي {$this->number($cap)} أيام، استُخدم {$this->number($usedThisYear)} أيام، والمتبقي {$this->number(max(0, $cap - $usedThisYear))} يوم، بينما الطلب {$this->number($days)} أيام.");
            }
        }
        if ($type->policy_code === 'annual' && $employee->hire_date && Carbon::parse($employee->hire_date)->addMonths((int) $this->policy('annual_wait_months', '6'))->gt($start)) {
            $earlyAllowed = filter_var($this->policy('allow_early_leave', '1'), FILTER_VALIDATE_BOOLEAN);
            if (!$leave->is_early_grant || !$earlyAllowed) {
                $eligibleAt = Carbon::parse($employee->hire_date)->addMonths((int) $this->policy('annual_wait_months', '6'));
                $messages = ['start_date' => "لا يمكن اعتماد {$type->name}: يلزم إتمام {$this->policy('annual_wait_months', '6')} أشهر خدمة. يصبح الموظف مستحقًا في {$eligibleAt->format('d/m/Y')}."];
                // The screen offers a confirm button only when the company allows exceptional grants.
                if ($earlyAllowed) $messages['early_grant_available'] = '1';
                throw ValidationException::withMessages($messages);
            }
        }
        if ($type->policy_code === 'casual') {
            $perRequest = (float) $this->policyFor('casual_max_days_request', '2', $employee->company_id ? (int) $employee->company_id : null);
            if ($perRequest > 0 && $days > $perRequest) $this->fail('days', "لا يمكن اعتماد الإجازة العارضة: الحد الأقصى للطلب الواحد {$this->number($perRequest)} أيام.");
            $used = $this->approvedDays($employee, $start->year, fn (LeaveType $item) => $item->policy_code === 'casual', $excludeId);
            $cap = (float) $this->policy('casual_max_days_year', '7');
            if ($used + $days > $cap) $this->fail('days', "لا يمكن اعتماد الإجازة العارضة: الحد السنوي {$this->number($cap)} أيام، استُخدم {$this->number($used)} أيام، والمتبقي {$this->number(max(0, $cap - $used))} يوم، بينما الطلب {$this->number($days)} أيام.");
        }
        if ($type->deducts_annual_balance) {
            $cycle = $this->leaveCycle($employee, $start);
            $balance = $this->balance($employee, $start);
            // Reserved availability: every approved leave in the cycle, past
            // and future, already counts, so two future requests can never
            // overspend the same balance even though the as-of display has
            // not consumed them yet.
            // Capacity, not the as-of remainder: allocation order never
            // changes the total, so this is stable for reservation checks.
            $usable = $balance['usable_capacity'];
            $used = $this->approvedDaysWithinCycle($employee, $cycle, fn (LeaveType $item) => (bool) $item->deducts_annual_balance, $excludeId);
            if ($used + $days > $usable && (!$leave->is_early_grant || $this->enforceBalanceOnEarlyGrant)) $this->fail('days', "لا يمكن اعتماد {$type->name}: الرصيد السنوي المتاح {$this->number($usable)} يوم، محجوز {$this->number($used)} أيام، والمتبقي {$this->number(max(0, $usable - $used))} يوم، بينما الطلب {$this->number($days)} أيام.");
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

    /** The employee's first cycle starts on their hire date. */
    private function firstCycleStart(Employee $employee, Carbon $referenceDate): Carbon
    {
        return $employee->hire_date
            ? Carbon::parse($employee->hire_date)->startOfDay()
            : $referenceDate->copy()->startOfYear();
    }

    /**
     * Usable-balance figures for one employee at a reference date, built from
     * chronological per-cycle buckets and one FIFO allocation pass.
     *
     * Buckets (oldest first):
     *  - tracked era (cycle starts on/after the dated carryover baseline):
     *    capacity = policy entitlement of the cycle's own start date, usage =
     *    recorded deductible leaves of that cycle;
     *  - pre-baseline cycles: never reconstructed. Only an explicit confirmed
     *    opening entry counts - a persisted row whose manual_adjustment a
     *    human recorded (with its date in the row's timestamps); the cycle's
     *    calculated entitlement/usage is not evidence, since balance rows are
     *    materialized automatically on display;
     *  - the current cycle: capacity = entitlement (the optional manual
     *    override applies here only, never retroactively) plus the current
     *    row's manual_adjustment, usage = recorded leaves counted up to the
     *    reference date (future approved leave is not consumed in the as-of
     *    display but stays reserved through the approval validation, which
     *    runs the same allocation over whole-cycle usage).
     *
     * Allocation is strictly oldest-first: each cycle's usage drains the
     * oldest remaining bucket before any newer one, so a bucket's remaining
     * always carries its true age for the 3-year settlement clock, and the
     * sum of remainders equals max(0, total capacity - total usage).
     *
     * @return array{buckets:array<int,array{year:int,start:Carbon,end:Carbon,capacity:float,used:float,remaining:float,source:string}>,carried:float,usable_capacity:float,remaining:float,advance:float}
     */
    public function allocate(Employee $employee, Collection $deductingLeaves, array $currentCycle, float $currentUsage, ?Collection $openingEntries = null, ?int $companyId = null): array
    {
        $baseline = $this->effectiveBaseline($companyId);
        $buckets = [];
        $cursor = $this->firstCycleStart($employee, $currentCycle['start']);
        while ($cursor->lt($currentCycle['start'])) {
            $cycle = ['start' => $cursor->copy(), 'end' => $cursor->copy()->addYear()->subDay()];
            $entry = $openingEntries?->get($cycle['start']->year);
            if (!$cycle['start']->lt($baseline)) {
                // Tracked era: full records. A signed opening entry on the
                // cycle adjusts its capacity (negative reduces, clamped at 0).
                $capacity = $this->entitlement($employee, $cycle['start']) + (float) ($entry->amount ?? 0);
                $buckets[] = [
                    'year' => $cycle['start']->year, 'start' => $cycle['start'], 'end' => $cycle['end'],
                    'capacity' => max(0.0, $capacity),
                    'used' => (float) $deductingLeaves->sum(fn (Leave $leave) => $this->daysWithinCycle($leave, $cycle)),
                    'remaining' => 0.0, 'source' => 'tracked',
                ];
            } elseif ($entry && !$entry->is_confirmed_zero && (float) $entry->amount != 0.0) {
                // Pre-baseline: only an explicit confirmed opening entry
                // counts; a confirmed-zero entry initializes the cycle with
                // no carry, and no entry at all means unknown (no accrual).
                $buckets[] = [
                    'year' => $cycle['start']->year, 'start' => $cycle['start'], 'end' => $cycle['end'],
                    'capacity' => max(0.0, (float) $entry->amount),
                    'used' => 0.0, 'remaining' => 0.0, 'source' => 'opening',
                ];
            }
            $cursor = $cycle['end']->copy()->addDay();
        }
        $currentEntry = $openingEntries?->get($currentCycle['start']->year);
        $currentCapacity = ($employee->manual_annual_balance !== null
            ? (float) $employee->manual_annual_balance
            : $this->entitlement($employee, $currentCycle['start'])) + (float) ($currentEntry->amount ?? 0);
        $buckets[] = [
            'year' => $currentCycle['start']->year, 'start' => $currentCycle['start'], 'end' => $currentCycle['end'],
            'capacity' => max(0.0, $currentCapacity), 'used' => $currentUsage, 'remaining' => 0.0, 'source' => 'current',
        ];

        foreach ($buckets as $i => $bucket) {
            $buckets[$i]['remaining'] = max(0.0, $bucket['capacity']);
        }
        $advance = 0.0;
        foreach ($buckets as $i => $bucket) {
            $usage = $bucket['used'];
            for ($j = 0; $usage > 0 && $j <= $i; $j++) {
                $take = min($buckets[$j]['remaining'], $usage);
                $buckets[$j]['remaining'] = round($buckets[$j]['remaining'] - $take, 2);
                $usage = round($usage - $take, 2);
            }
            $advance += $usage; // usage beyond every bucket: recorded as advance
        }

        $carried = 0.0;
        foreach ($buckets as $bucket) {
            if ($bucket['source'] !== 'current') $carried += $bucket['remaining'];
        }
        $remaining = round(array_sum(array_column($buckets, 'remaining')), 2);
        return [
            'buckets' => $buckets,
            'carried' => round($carried, 2),
            'usable_capacity' => round(array_sum(array_column($buckets, 'capacity')), 2),
            'remaining' => $remaining,
            'advance' => round($advance, 2),
        ];
    }

    /**
     * True when pre-baseline cycles exist without any explicit confirmed
     * opening entry: history there is unknown, carried stays zero for it, and
     * the setup alert tells authorized users to record opening balances.
     */
    public function hasUninitializedHistory(Employee $employee, array $currentCycle, ?Collection $openingEntries = null, ?int $companyId = null): bool
    {
        $baseline = $this->effectiveBaseline($companyId);
        if (!$this->firstCycleStart($employee, $currentCycle['start'])->lt($baseline)) return false;
        $cursor = $this->firstCycleStart($employee, $currentCycle['start']);
        while ($cursor->lt($baseline) && $cursor->lt($currentCycle['start'])) {
            // Any explicit entry initializes the cycle — including a
            // confirmed-zero, which is a decision, not missing data.
            if ($openingEntries?->has($cursor->year)) return false;
            $cursor = $cursor->copy()->addYear();
        }
        return true;
    }

    /** Confirmed opening entries for one employee, keyed by cycle year. */
    public function openingEntriesFor(Employee $employee): Collection
    {
        return DB::table('leave_opening_entries')->where('employee_id', $employee->id)->get()->keyBy('cycle_year');
    }

    /**
     * The explicit dated baseline from which cycles are system-tracked for
     * carry accrual. Self-activates once (persisted) when no company set it.
     */
    /**
     * The persisted carryover baseline, or null when the company has not
     * confirmed one yet. Reading never writes: activation is an explicit,
     * authorized save on the opening-balances page, so no balance page view
     * silently initializes company policy.
     */
    public function carryoverBaseline(?int $companyId = null): ?Carbon
    {
        $companyId = $companyId ?? (auth()->check() && !auth()->user()->isSuperAdmin() ? auth()->user()->company_id : null);
        $query = DB::table('leave_policy_settings')->where('setting_key', 'leave_carryover_baseline');
        if ($companyId) $query->where('company_id', $companyId); else $query->whereNull('company_id');
        $value = $query->value('setting_value');
        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * The cutoff actually applied while no baseline is confirmed: today.
     * Pre-cutoff cycles then count only via explicit opening entries, and the
     * setup alert points authorized users at the page where they confirm the
     * real date. Nothing is persisted until they do.
     */
    public function effectiveBaseline(?int $companyId = null): Carbon
    {
        return $this->carryoverBaseline($companyId) ?? today();
    }

    /** Leave days inside [from, to], prorated like daysWithinCycle for partial overlaps. */
    private function daysWithinRange(Leave $leave, Carbon $from, Carbon $to): float
    {
        return $this->daysWithinCycle($leave, ['start' => $from->copy()->startOfDay(), 'end' => $to->copy()->startOfDay()]);
    }

    /** The annual leave cycle in force at a reference date (hire-anniversary based). */
    public function cycleFor(Employee $employee, Carbon|int $referenceDate): array
    {
        return $this->leaveCycle($employee, $this->referenceDate($referenceDate));
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
