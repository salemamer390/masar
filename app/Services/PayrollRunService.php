<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Penalty;
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

    public function generate(Carbon $month): PayrollRun
    {
        return DB::transaction(function () use ($month) {
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
                $inputs = $this->approvedInputs($employee, $month, $structure);
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
                    'absence_days'=>$calculation['absence_days'], 'penalty_days'=>$calculation['penalty_days'], 'bonus'=>$calculation['bonus'], 'loans'=>$calculation['loans'], 'other_earnings'=>$calculation['other_earnings'], 'other_deductions'=>$calculation['other_deductions'],
                    'deductions' => $calculation['monthly_deductions'], 'gross_salary' => $calculation['gross'],
                    'insurance_wage' => $calculation['insurance_wage'], 'employee_insurance' => $calculation['employee_insurance'],
                    'income_tax' => $calculation['income_tax'], 'martyrs_fund' => $calculation['martyrs_fund'],
                    'employer_insurance' => $calculation['employer_insurance'], 'employee_cost' => $calculation['employee_cost'],
                    'fixed_deductions' => 0, 'absence_deduction' => $calculation['absence_deduction'],
                    'attendance_deduction' => $calculation['absence_deduction'], 'penalties' => $calculation['penalty_deduction'],
                    'penalty_deduction' => $calculation['penalty_deduction'], 'net_salary' => $calculation['net_monthly'],
                    'status' => 'Draft', 'is_locked' => false,
                    'calculation_snapshot' => array_merge($structure, $calculation, [
                        'inputs' => $inputs, 'settings' => $run->settings_snapshot,
                        'insurance_due' => $insuranceDue,
                        'service_start' => $serviceWindow['start']->toDateString(),
                        'service_end' => $serviceWindow['end']->toDateString(),
                        'paid_days' => $serviceWindow['days'],
                        'proration_factor' => $serviceWindow['factor'],
                    ]),
                ];
                PayrollRecord::updateOrCreate(['employee_id' => $employee->id, 'payroll_month' => $month->toDateString()], $payload);
            }

            // Reconciliation is deliberately limited to the current draft
            // run. Approved and locked runs are rejected above and remain
            // immutable historical snapshots.
            if ($eligibleEmployeeIds) {
                $run->records()
                    ->whereNotIn('employee_id', $eligibleEmployeeIds)
                    ->delete();
            } else {
                $run->records()->delete();
            }
            ActivityLog::record('payroll_generate', 'Payroll run generated.', $run, null, $run->toArray(), 'payroll');
            return $run->fresh();
        });
    }

    /** Build payroll inputs exclusively from finalized records within the payroll month. */
    private function approvedInputs(Employee $employee, Carbon $month, array $structure): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from, $to])
            ->where('approval_status', 'Approved')->get();
        $absenceRecords = $attendance->filter(fn (Attendance $record) => in_array($record->status, ['Absent', 'غياب'], true));
        $overtimeMinutes = (int) $attendance->sum('overtime_minutes');
        $penalties = Penalty::with('penaltyType')->where('employee_id', $employee->id)->whereBetween('penalty_date', [$from, $to])
            ->whereIn('status', ['Approved', 'معتمد'])->get();
        $approvedLeaves = Leave::with('leaveType')->where('employee_id', $employee->id)->where('status', 'Approved')
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get();
        $unpaidLeaveDays = $approvedLeaves
            ->filter(fn (Leave $leave) => $leave->leaveType && !$leave->leaveType->is_paid)
            ->sum(fn (Leave $leave) => $this->leaveDaysWithinMonth($leave, $month));
        $penaltyDays = $penalties->sum(fn (Penalty $penalty) => $penalty->deduction_days ?? $penalty->penaltyType?->default_deduction_days ?? 0);

        return [
            'ot_day_hours' => round($overtimeMinutes / 60, 2), 'ot_night_hours' => 0,
            'absence_days' => $absenceRecords->count() + $unpaidLeaveDays, 'penalty_days' => $penaltyDays,
            'bonus'=>0, 'loans'=>0, 'other_earnings'=>0, 'other_deductions'=>0,
            'approved_attendance_ids' => $attendance->pluck('id')->all(),
            'absence_attendance_ids' => $absenceRecords->pluck('id')->all(),
            'approved_leave_ids' => $approvedLeaves->pluck('id')->all(), 'approved_penalty_ids' => $penalties->pluck('id')->all(),
        ];
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

    /** Salary earnings are prorated by 30 days; statutory amounts stay full-month. */
    public function applyProration(array $calculation, float $factor): array
    {
        $factor = min(1, max(0, $factor));
        if ($factor >= 1) {
            return $calculation + [
                'proration_factor' => 1.0,
                'employee_cost' => round((float) $calculation['entitlements'] + (float) $calculation['employer_insurance'], 2),
            ];
        }

        $entitlements = round((float) $calculation['entitlements'] * $factor, 2);
        return array_merge($calculation, [
            'entitlements' => $entitlements,
            'net_monthly' => round($entitlements - (float) $calculation['monthly_deductions'], 2),
            'employee_cost' => round($entitlements + (float) $calculation['employer_insurance'], 2),
            'proration_factor' => $factor,
        ]);
    }

    private function serviceWindow(Employee $employee, Carbon $month): ?array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
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
            $contractEnd = $this->renewedContractEnd(Carbon::parse($employee->contract_start_date), $monthEnd);
        }
        if (!$contractEnd && $employee->hire_date) {
            $contractEnd = $this->renewedContractEnd(Carbon::parse($employee->hire_date), $monthEnd);
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
            abort_if(!in_array($run->status, ['Draft', 'Approved'], true), 422, 'لا يمكن حذف مسير مقفول أو مصروف.');
            $snapshot = $run->toArray();
            $run->records()->delete();
            $run->delete();
            ActivityLog::record('payroll_run_delete', 'Payroll draft deleted.', null, $snapshot, null, 'payroll');
        });
    }
}
