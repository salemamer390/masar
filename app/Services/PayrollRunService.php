<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Penalty;
use App\Models\HrRequest;
use App\Models\PayrollRecord;
use App\Models\PayrollRun;
use App\Models\Salary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\Payroll\OvertimeEngine;

class PayrollRunService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PayrollSettingsService $settings,
        private readonly OvertimeEngine $overtime,
        private readonly HrOperationalSettingsService $hrSettings,
    )
    {
    }

    /**
     * Generate a whole draft run, or refresh only selected employees when
     * $employeeIds is supplied. Partial generation deliberately skips draft
     * reconciliation so unrelated employee records are never removed.
     */
    public function generate(Carbon $month, ?array $employeeIds = null): PayrollRun
    {
        return DB::transaction(function () use ($month, $employeeIds) {
            $companyId = auth()->user()?->company_id;
            $run = PayrollRun::lockForUpdate()->firstOrCreate(
                ['company_id' => $companyId, 'payroll_month' => $month->toDateString()],
                ['company_id' => $companyId, 'status' => 'Draft', 'created_by' => auth()->id(), 'settings_snapshot' => $this->settings->snapshot()]
            );
            if (in_array($run->status, ['Approved', 'Locked'], true)) abort(422, 'Approved or locked payroll runs cannot be regenerated.');
            $run->update(['settings_snapshot' => $this->settings->snapshot()]);

            $excludedStatuses = ['Pending', 'Suspended', 'Terminated', 'قيد التعيين', 'موقوف', 'منتهي الخدمة'];
            $employees = Employee::query()
                ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
                ->where(function ($query) use ($excludedStatuses, $month) {
                    $query->whereDoesntHave('status', fn ($status) => $status->whereIn('name', $excludedStatuses))
                        ->orWhere(function ($terminated) use ($month) {
                            $terminated->whereHas('status', fn ($status) => $status->whereIn('name', ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة']))
                                ->whereBetween('termination_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()]);
                        });
                })
                ->orderBy('id')->cursor();

            // Keep track of the employees that are valid for this run. A draft
            // can be regenerated after employee/status/contract changes, so
            // records that no longer qualify must be removed from the draft.
            $eligibleEmployeeIds = [];

            foreach ($employees as $employee) {
                $serviceWindow = $this->serviceWindow($employee, $month);
                if ($serviceWindow === null) continue;
                // A historical salary can be the valid one for a past payroll month.
                $salary = Salary::where('employee_id', $employee->id)
                    ->where('effective_from', '<=', $month->copy()->endOfMonth()->toDateString())
                    ->latest('effective_from')->latest('id')->first();
                if (!$salary) continue;
                $eligibleEmployeeIds[] = $employee->id;
                $structure = $this->calculator->structureForSalary($salary);
                $inputs = $this->approvedInputs($employee, $month, $structure, $salary);
                $insuranceDue = $this->insuranceIsDueForMonth($employee, $month);
                $calculation = $this->calculator->monthly(
                    (float) $structure['gross'],
                    $inputs,
                    $salary->insurance_wage ? (float) $salary->insurance_wage : null,
                    (bool) $salary->no_insurance || !$insuranceDue,
                );
                $calculation = $this->applyStructureStatutoryValues($calculation, $salary, $insuranceDue);
                $calculation = $this->applyProration($calculation, $serviceWindow['factor']);
                $payload = [
                    'payroll_run_id' => $run->id, 'salary_id' => $salary->id,
                    'agreed_net_salary' => (float) $salary->net_salary,
                    'basic_salary' => $structure['basic_salary'], 'allowances' => $structure['allowances'],
                    'housing_allowance' => $calculation['housing_allowance'], 'transportation_allowance' => $calculation['transportation_allowance'],
                    'meal_allowance' => $calculation['meal_allowance'], 'clothing_allowance' => $calculation['clothing_allowance'],
                    'overtime_amount' => $calculation['overtime_amount'], 'total_overtime' => $calculation['overtime_amount'],
                    'day_overtime_hours' => $calculation['ot_day_hours'], 'day_overtime_amount' => $calculation['ot_day_amount'],
                    'night_overtime_hours' => $calculation['ot_night_hours'], 'night_overtime_amount' => $calculation['ot_night_amount'],
                    'ot_day_hours'=>$calculation['ot_day_hours'], 'ot_night_hours'=>$calculation['ot_night_hours'], 'ot_day_amount'=>$calculation['ot_day_amount'], 'ot_night_amount'=>$calculation['ot_night_amount'],
                    'absence_days'=>$calculation['absence_days'], 'penalty_days'=>$calculation['penalty_days'], 'bonus'=>$calculation['bonus'], 'incentives'=>$calculation['incentives'], 'commission'=>$calculation['commission'], 'profit_share'=>$calculation['profit_share'], 'loans'=>$calculation['loans'], 'other_earnings'=>$calculation['other_earnings'], 'other_deductions'=>$calculation['other_deductions'],
                    'deductions' => $calculation['monthly_deductions'], 'gross_salary' => $calculation['gross'],
                    'insurance_wage' => $calculation['insurance_wage'], 'employee_insurance' => $calculation['employee_insurance'],
                    'income_tax' => $calculation['income_tax'], 'martyrs_fund' => $calculation['martyrs_fund'],
                    'employer_insurance' => $calculation['employer_insurance'], 'employee_cost' => $calculation['employee_cost'],
                    'fixed_deductions' => 0, 'absence_deduction' => $calculation['absence_deduction'],
                    'attendance_deduction' => $calculation['absence_deduction'], 'penalties' => $calculation['penalty_deduction'],
                    'penalty_deduction' => $calculation['penalty_deduction'], 'net_salary' => $calculation['net_monthly'],
                    'status' => 'Draft', 'is_locked' => false,
                    'calculation_snapshot' => array_merge($structure, $calculation, [
                        'inputs' => $inputs, 'advance_details' => $inputs['advance_details'] ?? [], 'settings' => $run->settings_snapshot,
                        'insurance_due' => $insuranceDue,
                        'service_start' => $serviceWindow['start']->toDateString(),
                        'service_end' => $serviceWindow['end']->toDateString(),
                        'paid_days' => $serviceWindow['days'],
                        'proration_factor' => $serviceWindow['factor'],
                    ]),
                ];
                PayrollRecord::withoutGlobalScopes()->updateOrCreate(['employee_id' => $employee->id, 'payroll_month' => $month->toDateString()], $payload);
            }

            // Reconciliation is deliberately limited to the current draft
            // run. Approved and locked runs are rejected above and remain
            // immutable historical snapshots.
            if ($employeeIds === null) {
                if ($eligibleEmployeeIds) {
                    $run->records()
                        ->whereNotIn('employee_id', $eligibleEmployeeIds)
                        ->delete();
                } else {
                    $run->records()->delete();
                }
            }
            ActivityLog::record('payroll_generate', 'Payroll run generated.', $run, null, $run->toArray(), 'payroll');
            return $run->fresh();
        });
    }

    /** Refresh the affected employee in every open draft run for this company. */
    public function refreshEmployeeInOpenDrafts(int $employeeId, ?Carbon $from = null, ?Carbon $to = null): void
    {
        $companyId = auth()->user()?->company_id;
        $runs = PayrollRun::query()
            ->where('status', 'Draft')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($from, fn ($query) => $query->whereDate('payroll_month', '>=', $from->copy()->startOfMonth()))
            ->when($to, fn ($query) => $query->whereDate('payroll_month', '<=', $to->copy()->startOfMonth()))
            ->get();

        foreach ($runs as $run) {
            $this->generate($run->payroll_month->copy(), [$employeeId]);
        }
    }

    /** Build payroll inputs exclusively from finalized records within the payroll month. */
    private function approvedInputs(Employee $employee, Carbon $month, array $structure, ?Salary $salary = null): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();
        // Attendance records are effective immediately; no separate approval is required.
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from, $to])->get();
        $absenceRecords = $attendance->filter(fn (Attendance $record) => in_array($record->status, ['Absent', 'غياب'], true));
        $overtime = $this->approvedOvertime($employee, $attendance);
        $otherRequests = HrRequest::with('requestType')->where('employee_id', $employee->id)->whereNotIn('status', ['Rejected', 'مرفوض'])
            ->where(function ($query) {
                $query->where('request_type', 'other')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'other'));
            })->get()->filter(function ($request) use ($from, $to) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $date = $payload['request_date'] ?? $payload['start_date'] ?? $request->created_at?->toDateString();
                return $date && Carbon::parse($date)->betweenIncluded(Carbon::parse($from), Carbon::parse($to));
            });
        $otherEarnings = (float) $otherRequests->filter(fn ($request) => ($request->payload['impact_type'] ?? null) === 'earning')->sum(fn ($request) => (float) ($request->payload['amount'] ?? 0));
        $otherDeductions = (float) $otherRequests->filter(fn ($request) => ($request->payload['impact_type'] ?? null) === 'deduction')->sum(fn ($request) => (float) ($request->payload['amount'] ?? 0));
        $penalties = Penalty::with('penaltyType')->where('employee_id', $employee->id)->whereBetween('penalty_date', [$from, $to])
            ->whereIn('status', ['Approved', 'معتمد'])->get();
        $approvedLeaves = Leave::with('leaveType')->where('employee_id', $employee->id)->where('status', 'Approved')
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get();
        $unpaidLeaveDays = $approvedLeaves
            ->filter(fn (Leave $leave) => $leave->leaveType && !$leave->leaveType->is_paid)
            ->sum(fn (Leave $leave) => $this->leaveDaysWithinMonth($leave, $month));
        $penaltyDays = $penalties->sum(fn (Penalty $penalty) => $penalty->deduction_days ?? $penalty->penaltyType?->default_deduction_days ?? 0);
        $advancePlan = $this->advanceDeduction($employee, $month, (float) $structure['gross']);

        return [
            'ot_day_hours' => round($overtime['day_minutes'] / 60, 2), 'ot_night_hours' => round($overtime['night_minutes'] / 60, 2),
            'absence_days' => $absenceRecords->count() + $unpaidLeaveDays, 'penalty_days' => $penaltyDays,
            'bonus'=>0, 'incentives'=>(float) ($salary?->incentives ?? 0), 'commission'=>(float) ($salary?->commission ?? 0), 'profit_share'=>(float) ($salary?->profit_share ?? 0), 'advance_earnings'=>$advancePlan['grant_amount'], 'loans'=>$advancePlan['amount'], 'advance_details'=>$advancePlan['details'], 'other_earnings'=>$otherEarnings + (float) ($salary?->other_earnings ?? 0), 'other_deductions'=>$otherDeductions,
            'approved_attendance_ids' => $attendance->pluck('id')->all(), 'approved_overtime_request_ids' => $overtime['request_ids'], 'approved_other_request_ids' => $otherRequests->pluck('id')->all(),
            'absence_attendance_ids' => $absenceRecords->pluck('id')->all(),
            'approved_leave_ids' => $approvedLeaves->pluck('id')->all(), 'approved_penalty_ids' => $penalties->pluck('id')->all(),
        ];
    }

    /** Approved advances start in the month after the request date and are capped at 10% of gross monthly salary. */
    private function advanceDeduction(Employee $employee, Carbon $month, float $gross): array
    {
        $requests = HrRequest::with('requestType')
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', ['Rejected', 'مرفوض'])
            ->where(function ($query) {
                $query->where('request_type', 'advance')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'advance'));
            })->get();
        $priorRecords = PayrollRecord::where('employee_id', $employee->id)
            ->where('payroll_month', '<', $month->toDateString())
            ->get(['payroll_month', 'calculation_snapshot']);
        $monthlyCap = round(max(0, $gross) * 0.10, 2);
        $remainingCapacity = $monthlyCap;
        $total = 0.0;
        $grantAmount = 0.0;
        $details = [];
        foreach ($requests as $request) {
            $payload = is_array($request->payload) ? $request->payload : [];
            $amount = (float) ($payload['amount'] ?? 0);
            $requestDate = $payload['request_date'] ?? $request->created_at?->toDateString();
            if ($amount <= 0 || !$requestDate) continue;
            $startMonth = Carbon::parse($requestDate)->startOfMonth()->addMonth();
            if ($month->copy()->startOfMonth()->equalTo(Carbon::parse($requestDate)->startOfMonth())) {
                $grantAmount += $amount;
                continue;
            }
            if ($month->copy()->startOfMonth()->lt($startMonth)) continue;
            $paid = 0.0;
            foreach ($priorRecords as $record) {
                foreach ((array) data_get($record->calculation_snapshot, 'advance_details', []) as $detail) {
                    if ((int) ($detail['request_id'] ?? 0) === (int) $request->id) $paid += (float) ($detail['installment'] ?? 0);
                }
            }
            $remaining = round(max(0, $amount - $paid), 2);
            if ($remaining <= 0 || $remainingCapacity <= 0) continue;
            $installment = round(min($remaining, $remainingCapacity), 2);
            $total += $installment;
            $remainingCapacity = round($remainingCapacity - $installment, 2);
            $details[] = [
                'request_id' => $request->id,
                'amount' => $amount,
                'paid_before' => round($paid, 2),
                'installment' => $installment,
                'remaining_after' => round($remaining - $installment, 2),
                'start_month' => $startMonth->toDateString(),
            ];
        }
        return ['amount' => round($total, 2), 'grant_amount' => round($grantAmount, 2), 'details' => $details];
    }

    /** Non-rejected overtime requests authorize potential overtime immediately. */
    private function approvedOvertime(Employee $employee, $attendance): array
    {
        $requests = HrRequest::with('requestType')
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', ['Rejected', 'مرفوض'])
            ->where(function ($query) {
                $query->where('request_type', 'overtime')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'overtime'));
            })->get();
        $dayMinutes = 0;
        $nightMinutes = 0;
        $requestIds = [];
        foreach ($attendance as $record) {
            [$potentialDay, $potentialNight] = $this->potentialOvertime($employee, $record);
            $periods = $requests->filter(function ($request) use ($record) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $start = $payload['start_date'] ?? $payload['request_date'] ?? $request->created_at?->toDateString();
                $end = $payload['end_date'] ?? $start;
                return $start && $record->attendance_date?->betweenIncluded(Carbon::parse($start), Carbon::parse($end));
            });
            $authorizedDay = 0.0;
            $authorizedNight = 0.0;
            foreach ($periods as $request) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $start = $payload['start_date'] ?? $payload['request_date'] ?? $request->created_at?->toDateString();
                $end = $payload['end_date'] ?? $start;
                if (!$start || !$record->attendance_date?->betweenIncluded(Carbon::parse($start), Carbon::parse($end))) continue;
                $hours = (float) ($payload['hours'] ?? 0);
                if ($hours <= 0) continue;
                $days = max(1, Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1);
                $authorizedMinutes = ($hours / $days) * 60;
                if (($payload['overtime_period'] ?? 'day') === 'night') $authorizedNight += $authorizedMinutes;
                else $authorizedDay += $authorizedMinutes;
                $requestIds[] = $request->id;
            }
            $dayMinutes += min($potentialDay, $authorizedDay);
            $nightMinutes += min($potentialNight, $authorizedNight);
        }
        return ['day_minutes' => $dayMinutes, 'night_minutes' => $nightMinutes, 'request_ids' => array_values(array_unique($requestIds))];
    }

    private function potentialOvertime(Employee $employee, Attendance $record): array
    {
        if (!$record->check_in || !$record->check_out) return [0, 0];
        $in = Carbon::parse($record->check_in)->hour * 60 + Carbon::parse($record->check_in)->minute;
        $out = Carbon::parse($record->check_out)->hour * 60 + Carbon::parse($record->check_out)->minute;
        $start = Carbon::parse($employee->shift_start ?: $this->hrSettings->start());
        $end = Carbon::parse($employee->shift_end ?: $this->hrSettings->end());
        $startMinutes = $start->hour * 60 + $start->minute;
        $endMinutes = $end->hour * 60 + $end->minute;
        return [max(0, $startMinutes - $in), max(0, $out - $endMinutes)];
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run->refresh();
            abort_if($run->status !== 'Draft', 422, 'Only draft payroll runs can be approved.');
            $run->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            $run->records()->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            ActivityLog::record('payroll_run_approve', 'Payroll run approved.', $run, ['status' => 'Draft'], ['status' => 'Approved'], 'payroll');
            return $run->fresh();
        });
    }

    public function lock(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run->refresh();
            abort_if($run->status !== 'Approved', 422, 'Only approved payroll runs can be locked.');
            $run->update(['status' => 'Locked', 'locked_by' => auth()->id(), 'locked_at' => now()]);
            $run->records()->update(['status' => 'Locked', 'is_locked' => true]);
            ActivityLog::record('payroll_run_lock', 'Payroll run locked with immutable snapshots.', $run, ['status' => 'Approved'], ['status' => 'Locked'], 'payroll');
            return $run->fresh();
        });
    }

    /**
     * Insurance and tax are approved with the salary structure. The payroll run
     * copies these fixed values and only adds the month's variable entries.
     */
    public function applyStructureStatutoryValues(array $calculation, Salary $salary, bool $insuranceDue = true): array
    {
        $insuranceWage = $insuranceDue ? (float) ($salary->applied_insurance_wage ?? $salary->insurance_wage ?? 0) : 0.0;
        $employeeInsurance = $insuranceDue ? (float) ($salary->employee_insurance ?? 0) : 0.0;
        $incomeTax = (float) ($salary->income_tax ?? 0);
        $martyrsFund = (float) ($salary->martyrs_fund ?? 0);
        $employerInsurance = $insuranceDue ? (float) ($salary->employer_insurance ?? 0) : 0.0;
        $deductions = $employeeInsurance + $incomeTax + $martyrsFund
            + (float) $calculation['absence_deduction'] + (float) $calculation['penalty_deduction']
            + (float) $calculation['loans'] + (float) $calculation['other_deductions'];

        return array_merge($calculation, [
            'insurance_wage' => $insuranceWage,
            'employee_insurance' => $employeeInsurance,
            'income_tax' => $incomeTax,
            'martyrs_fund' => $martyrsFund,
            'employer_insurance' => $employerInsurance,
            'employee_cost' => $insuranceDue
                ? (float) ($salary->employee_cost ?? ((float) $calculation['gross'] + $employerInsurance))
                : (float) $calculation['gross'],
            'monthly_deductions' => round($deductions, 2),
            'net_monthly' => round((float) $calculation['entitlements'] - $deductions, 2),
        ]);
    }

    /** Prorate fixed salary by 30 days; variable earnings (overtime, advances, bonuses) stay whole. */
    public function applyProration(array $calculation, float $factor): array
    {
        $factor = min(1, max(0, $factor));
        if ($factor >= 1) {
            return $calculation + [
                'proration_factor' => 1.0,
                'employee_cost' => round((float) $calculation['entitlements'] + (float) $calculation['employer_insurance'], 2),
            ];
        }

        $fixedEntitlements = (float) $calculation['basic_salary'] + (float) $calculation['allowances'];
        $variableEntitlements = (float) $calculation['entitlements'] - $fixedEntitlements;
        $nonProratedDeductions = (float) ($calculation['loans'] ?? 0) + (float) ($calculation['other_deductions'] ?? 0);
        $proratedDeductions = max(0, (float) $calculation['monthly_deductions'] - $nonProratedDeductions);
        $entitlements = round(($fixedEntitlements * $factor) + $variableEntitlements, 2);
        $deductions = round(($proratedDeductions * $factor) + $nonProratedDeductions, 2);
        return array_merge($calculation, [
            'entitlements' => $entitlements,
            'monthly_deductions' => $deductions,
            'net_monthly' => round((($fixedEntitlements - $proratedDeductions) * $factor) + $variableEntitlements - $nonProratedDeductions, 2),
            'employee_cost' => round($entitlements + (float) $calculation['employer_insurance'], 2),
            'proration_factor' => $factor,
        ]);
    }

    private function serviceWindow(Employee $employee, Carbon $month): ?array
    {
        $monthStart = $month->copy()->startOfMonth();
        $calendarMonthEnd = $month->copy()->endOfMonth();
        $monthEnd = $month->isSameMonth(Carbon::today())
            ? Carbon::today()->startOfDay()
            : $calendarMonthEnd;
        $status = strtolower(trim((string) $employee->status?->name));
        $terminal = in_array($status, ['terminated', 'منتهي الخدمة', 'منتهى الخدمة'], true);
        $eligibleWhileActive = in_array($status, ['', 'active', 'leave', 'إجازة'], true);

        // Suspended/pending employees are not payable. A terminated employee is
        // payable only for the month in which the termination occurred.
        if (!$eligibleWhileActive && !$terminal) return null;

        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : $monthStart->copy();
        $terminationDate = $employee->termination_date
            ? Carbon::parse($employee->termination_date)->startOfDay()
            : ($terminal && $employee->contract_end_date ? Carbon::parse($employee->contract_end_date)->startOfDay() : null);
        if ($terminal) {
            // No termination date means there is no reliable month to include;
            // do not accidentally include the employee in every future run.
            if (!$terminationDate || !$terminationDate->isSameMonth($month)) return null;
        }
        $contractEnd = $terminationDate;
        if (!$contractEnd && $employee->contract_start_date) {
            $contractEnd = $this->renewedContractEnd(Carbon::parse($employee->contract_start_date), $calendarMonthEnd);
        }
        if (!$contractEnd && $employee->hire_date) {
            $contractEnd = $this->renewedContractEnd(Carbon::parse($employee->hire_date), $calendarMonthEnd);
        }
        $contractEnd ??= ($employee->contract_end_date ? Carbon::parse($employee->contract_end_date) : $monthEnd->copy());
        $start = $hireDate->greaterThan($monthStart) ? $hireDate : $monthStart;
        $end = $contractEnd->lessThan($monthEnd) ? $contractEnd : $monthEnd;
        if ($start->greaterThan($end)) return null;

        $days = $start->diffInDays($end) + 1;
        return ['start' => $start, 'end' => $end, 'days' => $days, 'factor' => min(1, $days / 30)];
    }

    /** Return the end of the current annual renewal cycle. */
    private function renewedContractEnd(Carbon $start, ?Carbon $reference = null): Carbon
    {
        $end = $start->copy()->addYear()->subDay();
        $reference ??= Carbon::today();
        while ($end->lt($reference)) {
            $end->addYear();
        }
        return $end;
    }

    private function insuranceIsDueForMonth(Employee $employee, Carbon $month): bool
    {
        if (!$employee->hire_date) return true;

        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        return !($hireDate->isSameMonth($month) && !$hireDate->isSameDay($month->copy()->startOfMonth()));
    }

    /** Count only the part of an unpaid leave that belongs to this payroll month. */
    private function leaveDaysWithinMonth(Leave $leave, Carbon $month): int
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $leaveStart = Carbon::parse($leave->start_date);
        $leaveEnd = Carbon::parse($leave->end_date);
        $start = $leaveStart->greaterThan($monthStart) ? $leaveStart : $monthStart;
        $end = $leaveEnd->lessThan($monthEnd) ? $leaveEnd : $monthEnd;
        if ($start->greaterThan($end)) return 0;

        if (!$this->hrSettings->excludeRestAndHolidaysFromLeave()) {
            return $start->diffInDays($end) + 1;
        }

        $days = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (!$this->hrSettings->isWeeklyRest($date) && !$this->hrSettings->isHoliday($date)) {
                $days++;
            }
        }
        return $days;
    }

    public function destroyDraft(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run->refresh();
            abort_unless($run->status === 'Locked', 422, 'لا يمكن حذف إلا المسير المقفول نهائيًا.');
            $snapshot = $run->toArray();
            $run->records()->delete();
            $run->delete();
            ActivityLog::record('payroll_run_delete', 'Payroll draft deleted.', null, $snapshot, null, 'payroll');
        });
    }
}
