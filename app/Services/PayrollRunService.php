<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\Leave;
use App\Models\PayrollRecord;
use App\Models\PayrollRun;
use App\Models\Penalty;
use App\Models\Salary;
use App\Models\Settlement;
use App\Http\Controllers\JobDataController;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollRunService
{
    /** Records in these states were already paid out and are never rewritten by a regeneration. */
    private const PAID_STATUSES = ['Paid', 'تم الصرف'];

    private const FINAL_RECORD_STATUSES = ['Approved', 'معتمدة', 'معتمد', 'Locked', 'مغلق', 'مقفلة', 'Paid', 'تم الصرف'];

    private const EXCLUDED_STATUSES = ['Pending', 'Suspended', 'Terminated', 'قيد التعيين', 'موقوف', 'منتهي الخدمة'];

    private const TERMINATED_STATUSES = ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة'];

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PayrollSettingsService $settings,
        private readonly HrOperationalSettingsService $hrSettings,
    ) {
    }

    /* ------------------------------------------------------------------ *
     | Generation
     * ------------------------------------------------------------------ */

    /**
     * Generate a whole draft run, or refresh only selected employees when
     * $employeeIds is supplied. A company is always required: payroll is never
     * generated for "all companies".
     */
    public function generate(Carbon $month, ?array $employeeIds = null, ?int $companyId = null, ?PayrollRun $forRun = null): PayrollRun
    {
        $companyId = $companyId ?? CompanyContext::id();
        abort_if(!$companyId, 422, 'يجب تحديد الشركة لإنشاء مسير الرواتب.');
        // A transition run keeps its exact anchor date; monthly runs anchor on the month start.
        $month = $forRun ? $month->copy() : $month->copy()->startOfMonth();

        return CompanyContext::run($companyId, function () use ($month, $employeeIds, $companyId, $forRun) {
            return DB::transaction(function () use ($month, $employeeIds, $companyId, $forRun) {
                $periodOverride = null;
                if ($forRun) {
                    $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($forRun->id);
                    abort_if($run->status !== 'Draft', 422, 'Only draft payroll runs can be regenerated.');
                    abort_if(!$run->period_start || !$run->period_end, 422, 'Transition run is missing its period.');
                    $periodOverride = ['start' => $run->period_start->copy()->startOfDay(), 'end' => $run->period_end->copy()->startOfDay()];
                } else {
                    $creationPeriod = $this->hrSettings->payrollPeriod($month);
                    $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->firstOrCreate(
                        ['company_id' => $companyId, 'payroll_month' => $month->toDateString()],
                        [
                            'status' => 'Draft',
                            'created_by' => auth()->id(),
                            'settings_snapshot' => $this->settings->snapshot() + [
                                // The cycle this run was created under: close scheduling
                                // must never re-read the live settings for a historical run.
                                'period' => [
                                    'start_day' => $this->hrSettings->payrollPeriodStartDay(),
                                    'end_day' => $this->hrSettings->payrollPeriodEndDay(),
                                ],
                            ],
                            // Snapshotted at creation: a later grace-settings change never moves an existing run.
                            'closes_at' => $this->closesAtFor($creationPeriod['end'], $this->hrSettings->attendanceCorrectionDays())->toDateString(),
                        ]
                    );
                    if (in_array($run->status, ['Approved', 'Locked'], true)) {
                        abort(422, 'Approved or locked payroll runs cannot be regenerated.');
                    }
                    abort_if($run->status === 'Canceled', 422, 'مسير هذه الفترة اتلغى عند تغيير دورة الرواتب ولا يمكن إعادة توليده.');
                    if (!$run->wasRecentlyCreated && $run->period_start && $run->period_end) {
                        // A draft pinned by a cycle change keeps covering its original days.
                        $periodOverride = ['start' => $run->period_start->copy()->startOfDay(), 'end' => $run->period_end->copy()->startOfDay()];
                    }
                    if ($run->wasRecentlyCreated) {
                        // No overlap, ever: a month whose new-cycle period straddles days an
                        // existing run already covers (e.g. replaced by a transition run)
                        // must not start a second run over the same days.
                        $overlap = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
                            ->where('id', '!=', $run->id)->where('status', '!=', 'Canceled')
                            ->whereNotNull('period_start')->whereNotNull('period_end')
                            ->whereDate('period_start', '<=', $creationPeriod['end']->toDateString())
                            ->whereDate('period_end', '>=', $creationPeriod['start']->toDateString())
                            ->exists();
                        abort_if($overlap, 422, 'فترة المسير بتتداخل مع مسير موجود. راجع المسير الانتقالي اللي بيغطي نفس الأيام.');
                    }
                }
                $refreshedSnapshot = $this->settings->snapshot();
                $existingSnapshot = (array) ($run->settings_snapshot ?? []);
                // The creation-time cycle survives regeneration; only rows from before
                // period snapshotting pick up the current days here.
                $refreshedSnapshot['period'] = (array) ($existingSnapshot['period'] ?? [
                    'start_day' => $this->hrSettings->payrollPeriodStartDay(),
                    'end_day' => $this->hrSettings->payrollPeriodEndDay(),
                ]);
                $run->update(['settings_snapshot' => $refreshedSnapshot]);

                $eligibleIds = [];
                foreach ($this->payableEmployees($month, $companyId, $employeeIds, $periodOverride) as $item) {
                    $employee = $item['employee'];
                    $window = $item['window'];
                    $salary = $this->salaryFor((int) $employee->id, $month, $periodOverride);
                    if (!$salary) continue;
                    $eligibleIds[] = (int) $employee->id;

                    $structure = $this->calculator->structureForSalary($salary);
                    $inputs = $this->approvedInputs($employee, $month, $structure, $window, $run->is_transition ? $run : null);
                    $chargeMonths = $this->chargeMonthsFor($run, $month);
                    $insuranceMonths = count(array_filter($chargeMonths, fn ($chargeMonth) => $this->insuranceIsDueForMonth($employee, $chargeMonth)));
                    $insuranceDue = $insuranceMonths > 0;
                    $calculation = $this->calculate($salary, $structure, $inputs, $insuranceDue, (float) $window['factor'], max(1, $insuranceMonths), $run->is_transition);
                    $payload = $this->buildPayload($run, $salary, $structure, $calculation, $inputs, $insuranceDue, $window, max(1, $insuranceMonths));
                    $this->storeRecord($run, $employee, $month, $payload, $companyId);
                }

                // Records that no longer qualify (status change, salary removed...) leave the draft.
                $stale = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->whereNull('paid_at')
                    ->where(fn ($q) => $q->whereNull('is_locked')->orWhere('is_locked', false))
                    ->whereNotIn('status', self::FINAL_RECORD_STATUSES);
                if ($employeeIds === null) {
                    if ($eligibleIds) $stale->whereNotIn('employee_id', $eligibleIds);
                    $stale->delete();
                } else {
                    $gone = array_values(array_diff(array_map('intval', $employeeIds), $eligibleIds));
                    if ($gone) $stale->whereIn('employee_id', $gone)->delete();
                }

                if ($employeeIds === null) {
                    ActivityLog::record('payroll_generate', 'Payroll run generated.', $run, null, $run->toArray(), 'payroll');
                }

                $this->reapplyApprovedSettlements($run);

                return $run->fresh();
            });
        });
    }

    /**
     * An approved settlement folded into a draft run must survive regeneration:
     * the rebuild re-applies its lines so payroll neither silently drops the
     * settlement nor double-charges it (the same-month schedule is computed
     * before the settlement's recovery is counted).
     */
    private function reapplyApprovedSettlements(PayrollRun $run): void
    {
        $settlements = Settlement::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->where('status', 'Approved')
            ->where('reconciliation', 'draft_run')
            ->whereDate('anchor_month', Carbon::parse($run->payroll_month)->startOfMonth()->toDateString())
            ->get();
        foreach ($settlements as $settlement) {
            $record = PayrollRecord::withoutGlobalScopes()
                ->where('payroll_run_id', $run->id)
                ->where('employee_id', $settlement->employee_id)
                ->first();
            if (! $record || $record->is_locked || $record->paid_at !== null) continue;
            if ((int) data_get($record->calculation_snapshot, 'settlement.id') === (int) $settlement->id) continue;
            $this->applySettlementToRecord($record, $settlement);
            $settlement->forceFill(['payroll_run_id' => $run->id, 'payroll_record_id' => $record->id])->save();
        }
    }

    /**
     * Fold settlement lines into the employee's payroll record using the same
     * recalculation path as manual payroll edits, so salary, insurance, tax and
     * attendance keep following the existing rules unchanged. The advance
     * recovery raises this month's total loan deduction and each advance
     * absorbs it up to its remaining balance — the same shape as
     * PayrollController::updateInputs.
     */
    public function applySettlementToRecord(PayrollRecord $record, Settlement $settlement): PayrollRecord
    {
        abort_if($record->is_locked || $record->paid_at !== null || in_array($record->status, self::FINAL_RECORD_STATUSES, true), 422, 'لا يمكن تعديل سجل راتب معتمد أو مقفل أو مصروف.');

        $lines = [
            'leave_payout' => (float) $settlement->leave_payout_amount,
            'gratuity' => (float) $settlement->gratuity_amount,
            'manual_earnings' => (float) $settlement->manual_earnings,
            'advance_recovery' => (float) $settlement->advance_recovery_amount,
            'manual_deductions' => (float) $settlement->manual_deductions,
        ];

        $changes = [
            'other_earnings' => round((float) $record->other_earnings + $lines['leave_payout'] + $lines['gratuity'] + $lines['manual_earnings'], 2),
            'other_deductions' => round((float) $record->other_deductions + $lines['manual_deductions'], 2),
        ];

        if ($lines['advance_recovery'] > 0.004) {
            $details = (array) data_get($record->calculation_snapshot, 'advance_details', []);
            $availableTotal = round(collect($details)->sum(fn ($d) => (float) ($d['installment'] ?? 0) + (float) ($d['remaining_after'] ?? 0)), 2);
            $target = min(round((float) $record->loans + $lines['advance_recovery'], 2), $availableTotal);
            $remainingToAllocate = $target;
            $updated = [];
            foreach ($details as $detail) {
                $available = (float) ($detail['installment'] ?? 0) + (float) ($detail['remaining_after'] ?? 0);
                $installment = round(min($available, $remainingToAllocate), 2);
                $detail['installment'] = $installment;
                $detail['remaining_after'] = round($available - $installment, 2);
                $updated[] = $detail;
                $remainingToAllocate = round(max(0.0, $remainingToAllocate - $installment), 2);
            }
            $changes['loans'] = $target;
            $changes['advance_details'] = $updated;
        }

        $record = $this->recalculateRecord($record, $changes);
        $record->forceFill([
            'calculation_snapshot' => array_merge($record->calculation_snapshot ?? [], [
                'settlement' => ['id' => $settlement->id, 'lines' => $lines],
            ]),
        ])->save();

        return $record;
    }

    /** Refresh the affected employee in every open draft run of the employee's own company. */
    public function refreshEmployeeInOpenDrafts(int $employeeId, ?Carbon $from = null, ?Carbon $to = null): void
    {
        $companyId = Employee::withoutGlobalScopes()->whereKey($employeeId)->value('company_id');
        if (!$companyId) return;

        $runs = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'Draft')
            ->when($from, fn ($query) => $query->whereDate('payroll_month', '>=', $from->copy()->startOfMonth()))
            ->when($to, fn ($query) => $query->whereDate('payroll_month', '<=', $to->copy()->startOfMonth()))
            ->get();

        foreach ($runs as $run) {
            $this->generate($run->payroll_month->copy(), [$employeeId], (int) $companyId, $run->is_transition ? $run : null);
        }
    }

    /** Rebuild every editable draft for one company after a calculation rule changes. */
    public function refreshOpenDraftsForCompany(int $companyId): int
    {
        $runs = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', 'Draft')->orderBy('payroll_month')->get();
        foreach ($runs as $run) {
            $this->generate($run->payroll_month->copy(), null, $companyId, $run->is_transition ? $run : null);
        }
        return $runs->count();
    }

    /**
     * Insert or update the single record of this employee/month. Paid records and
     * records that belong to another approved/locked run are never touched; a draft
     * record that sits in a wrong run (for example an old run without a company)
     * is moved into this run.
     */
    private function storeRecord(PayrollRun $run, Employee $employee, Carbon $month, array $payload, int $companyId): bool
    {
        $existing = PayrollRecord::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('payroll_month', $month->toDateString())
            ->first();

        if (!$existing) {
            PayrollRecord::withoutGlobalScopes()->create(array_merge($payload, [
                'employee_id' => $employee->id,
                'payroll_month' => $month->toDateString(),
            ]));
            return true;
        }

        if ($existing->company_id !== null && (int) $existing->company_id !== $companyId) return false;
        if ($existing->is_locked || $existing->paid_at !== null || in_array($existing->status, self::FINAL_RECORD_STATUSES, true)) return false;

        if ($existing->payroll_run_id !== null && (int) $existing->payroll_run_id !== (int) $run->id) {
            $other = PayrollRun::withoutGlobalScopes()->find($existing->payroll_run_id);
            if ($other && in_array($other->status, ['Approved', 'Locked'], true)) return false;
        }

        $existing->update($payload);
        return true;
    }

    /** Run the full calculation for one salary: monthly inputs, statutory values, then proration. */
    public function calculate(Salary $salary, array $structure, array $inputs, bool $insuranceDue, float $factor, int $insuranceMonths = 1, bool $allowOverFullMonth = false): array
    {
        $calculation = $this->calculator->monthly(
            (float) $structure['gross'],
            $inputs,
            $salary->insurance_wage ? (float) $salary->insurance_wage : null,
            (bool) $salary->no_insurance || !$insuranceDue,
            $this->absenceBaseOverride($salary),
        );
        $calculation = $this->applyStructureStatutoryValues($calculation, $salary, $insuranceDue, $insuranceMonths);

        return $this->applyProration($calculation, $factor, $allowOverFullMonth);
    }

    /**
     * Absence-day deduction base under the Basic basis with absence-deducted
     * allowances configured: the contractual basic plus only the allowances
     * configured as absence-deducted, read from the stored salary row. An empty
     * allowance selection still means the contractual basic alone. Returns null
     * under Gross, where every allowance is already inside the gross - adding
     * them again would double-deduct - so the calculator keeps its behavior.
     */
    private function absenceBaseOverride(Salary $salary): ?float
    {
        if ($this->settings->absenceBasis() !== 'Basic') return null;
        $allowances = $this->settings->absenceDeductedAllowances();
        // Legacy rows without a contractual basic breakdown keep the calculator's own base.
        if ($salary->basic_salary === null) return null;

        $columns = [
            'housing' => 'housing_allowance', 'transportation' => 'transportation_allowance',
            'meal' => 'meal_allowance', 'clothing' => 'clothing_allowance', 'other' => 'other_allowances',
        ];
        $base = (float) $salary->basic_salary;
        foreach ($allowances as $key) {
            $base += (float) ($salary->{$columns[$key]} ?? 0);
        }

        return $base;
    }

    /** Map a finished calculation to the payroll_records columns and its audit snapshot. */
    public function buildPayload(PayrollRun $run, Salary $salary, array $structure, array $calculation, array $inputs, bool $insuranceDue, array $window, int $insuranceMonths = 1): array
    {
        // Persist the contractual amounts actually earned in the period: the
        // engine's internal basic/allowance split drives statutory math only.
        $contractualBasicEarned = round((float) ($salary->basic_salary ?? $calculation['basic_salary']) * $window['factor'], 2);
        // Itemized allowances are the contractual split when the structure has
        // one; a lump-only structure stores zeros so the drawer shows the
        // contractual lump instead of the engine's invented split.
        $hasContractualSplit = (float) $salary->housing_allowance + (float) $salary->transportation_allowance
            + (float) $salary->meal_allowance + (float) $salary->clothing_allowance > 0;
        $itemized = static fn (string $column): float => $hasContractualSplit
            ? round((float) ($salary->{$column} ?? 0) * $window['factor'], 2)
            : 0.0;

        return [
            'payroll_run_id' => $run->id, 'salary_id' => $salary->id,
            'agreed_net_salary' => (float) $salary->net_salary,
            'basic_salary' => $contractualBasicEarned,
            'allowances' => round((float) ($salary->allowances ?? $calculation['allowances']) * $window['factor'], 2),
            'housing_allowance' => $itemized('housing_allowance'), 'transportation_allowance' => $itemized('transportation_allowance'),
            'meal_allowance' => $itemized('meal_allowance'), 'clothing_allowance' => $itemized('clothing_allowance'),
            'overtime_amount' => $calculation['overtime_amount'], 'total_overtime' => $calculation['overtime_amount'],
            'day_overtime_hours' => $calculation['ot_day_hours'], 'day_overtime_amount' => $calculation['ot_day_amount'],
            'night_overtime_hours' => $calculation['ot_night_hours'], 'night_overtime_amount' => $calculation['ot_night_amount'],
            'ot_day_hours' => $calculation['ot_day_hours'], 'ot_night_hours' => $calculation['ot_night_hours'],
            'ot_day_amount' => $calculation['ot_day_amount'], 'ot_night_amount' => $calculation['ot_night_amount'],
            'official_holiday_days' => $calculation['official_holiday_days'], 'official_holiday_amount' => $calculation['official_holiday_amount'],
            'weekly_rest_days' => $calculation['weekly_rest_days'], 'weekly_rest_amount' => $calculation['weekly_rest_amount'],
            'absence_days' => $calculation['absence_days'], 'penalty_days' => $calculation['penalty_days'],
            'bonus' => $calculation['bonus'], 'incentives' => $calculation['incentives'], 'commission' => $calculation['commission'],
            'profit_share' => $calculation['profit_share'], 'loans' => $calculation['loans'],
            'other_earnings' => $calculation['other_earnings'], 'other_deductions' => $calculation['other_deductions'],
            'deductions' => $calculation['monthly_deductions'], 'gross_salary' => $calculation['gross'], 'monthly_total' => $calculation['monthly_total'],
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
                'insurance_months' => $insuranceDue ? $insuranceMonths : 0,
                'service_start' => $window['start']->toDateString(),
                'service_end' => $window['end']->toDateString(),
                'paid_days' => $window['days'],
                'proration_factor' => $window['factor'],
                'proration_basis' => $this->settings->prorationDays(),
                // «المستحق حتى اليوم» in the drawer and payslip is the same
                // contractual prorated basic stored on the record - not the
                // engine's internal split, which differs whenever the contractual
                // basic is not gross / 1.25.
                'to_date_entitlement' => $contractualBasicEarned,
            ]),
        ];
    }

    /** Recalculate one draft record after the user edited its monthly inputs. */
    public function recalculateRecord(PayrollRecord $record, array $changes): PayrollRecord
    {
        $snapshot = (array) $record->calculation_snapshot;
        $salary = Salary::withoutGlobalScopes()->findOrFail($record->salary_id);
        $run = PayrollRun::withoutGlobalScopes()->findOrFail($record->payroll_run_id);
        $inputs = array_merge((array) ($snapshot['inputs'] ?? []), $changes);
        $insuranceDue = (bool) ($snapshot['insurance_due'] ?? true);
        $window = [
            'start' => Carbon::parse($snapshot['service_start'] ?? $record->payroll_month),
            'end' => Carbon::parse($snapshot['service_end'] ?? $record->payroll_month),
            'days' => $snapshot['paid_days'] ?? $this->settings->prorationDays(),
            'factor' => (float) ($snapshot['proration_factor'] ?? 1.0),
        ];

        return CompanyContext::run((int) $record->company_id, function () use ($record, $salary, $run, $inputs, $insuranceDue, $window) {
            $structure = $this->calculator->structureForSalary($salary);
            $insuranceMonths = (int) ($snapshot['insurance_months'] ?? ($insuranceDue ? 1 : 0));
            $calculation = $this->calculate($salary, $structure, $inputs, $insuranceDue, (float) $window['factor'], max(1, $insuranceMonths), $run->is_transition);
            $payload = $this->buildPayload($run, $salary, $structure, $calculation, $inputs, $insuranceDue, $window, max(1, $insuranceMonths));
            unset($payload['payroll_run_id'], $payload['salary_id'], $payload['status'], $payload['is_locked']);
            $record->update($payload);

            return $record->fresh();
        });
    }

    /* ------------------------------------------------------------------ *
     | Eligibility and reconciliation
     * ------------------------------------------------------------------ */

    /**
     * The period of a month: the exact dates stored when its run was approved, otherwise the configured period.
     * Runs approved before the dates were stored have none and keep using the configured period (never guessed).
     */
    public function periodFor(Carbon $month, ?array $override = null): array
    {
        if ($override !== null) return $override;
        $companyId = CompanyContext::id();
        if ($companyId) {
            $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereDate('payroll_month', $month->copy()->startOfMonth()->toDateString())
                ->whereIn('status', ['Draft', 'Approved', 'Locked'])->whereNotNull('period_start')->whereNotNull('period_end')
                ->orderByRaw("CASE WHEN status = 'Draft' THEN 1 ELSE 0 END")->first();
            if ($run) return ['start' => $run->period_start->copy()->startOfDay(), 'end' => $run->period_end->copy()->startOfDay()];
        }

        return $this->hrSettings->payrollPeriod($month);
    }

    private function salaryFor(int $employeeId, Carbon $month, ?array $periodOverride = null): ?Salary
    {
        // A historical salary can be the valid one for a past payroll month.
        $periodEnd = $this->periodFor($month, $periodOverride)['end'];

        return Salary::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->latest('effective_from')->latest('id')->first();
    }

    /** Employees whose status allows payroll in this month (before the service-window check). */
    public function eligibleEmployees(Carbon $month, int $companyId, ?array $employeeIds = null, ?array $periodOverride = null): Collection
    {
        $period = $this->periodFor($month, $periodOverride);
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();

        return Employee::withoutGlobalScopes()->with('status')
            ->where('company_id', $companyId)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->where(function ($query) use ($periodStart, $periodEnd) {
                $query->whereDoesntHave('status', fn ($status) => $status->whereIn('name', self::EXCLUDED_STATUSES))
                    ->orWhere(function ($terminated) use ($periodStart, $periodEnd) {
                        $terminated->whereHas('status', fn ($status) => $status->whereIn('name', self::TERMINATED_STATUSES))
                            ->whereBetween('termination_date', [$periodStart, $periodEnd]);
                    });
            })
            ->orderBy('id')->get();
    }

    /** @return array<int, array{employee: Employee, window: array}> employees that must be paid this month */
    public function payableEmployees(Carbon $month, int $companyId, ?array $employeeIds = null, ?array $periodOverride = null): array
    {
        $payable = [];
        foreach ($this->eligibleEmployees($month, $companyId, $employeeIds, $periodOverride) as $employee) {
            $window = $this->serviceWindow($employee, $month, $periodOverride);
            if ($window === null) continue;
            $payable[] = ['employee' => $employee, 'window' => $window];
        }

        return $payable;
    }

    /** Ids of every employee that must appear in the run (used to batch the generation). */
    public function payableEmployeeIds(Carbon $month, int $companyId): array
    {
        return array_map(fn (array $item) => (int) $item['employee']->id, $this->payableEmployees($month, $companyId));
    }

    /** Compare who must be paid with who actually has a record, and say why anyone is missing. */
    public function reconciliationReport(PayrollRun $run): array
    {
        $companyId = (int) $run->company_id;
        $month = $run->payroll_month->copy()->startOfMonth();

        return CompanyContext::run($companyId, function () use ($run, $companyId, $month) {
            $payable = $this->payableEmployees($month, $companyId);
            $have = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->pluck('employee_id')->flip();
            $skipped = [];
            foreach ($payable as $item) {
                $employee = $item['employee'];
                if ($have->has($employee->id)) continue;
                $skipped[] = [
                    'employee_id' => (int) $employee->id,
                    'code' => $employee->employee_code,
                    'name' => $employee->full_name,
                    'reason' => $this->salaryFor((int) $employee->id, $month)
                        ? 'لم يُنشأ سجل لهذا الموظف، أعد توليد المسودة'
                        : 'لا يوجد هيكل راتب ساري حتى نهاية هذا الشهر',
                ];
            }

            return [
                'eligible' => count($payable),
                'records' => $have->count(),
                'skipped' => $skipped,
                'checked_at' => now()->toDateTimeString(),
            ];
        });
    }

    /* ------------------------------------------------------------------ *
     | Monthly inputs
     * ------------------------------------------------------------------ */

    /** Build payroll inputs from attendance, leaves, penalties and HR requests inside the service window. */
    private function approvedInputs(Employee $employee, Carbon $month, array $structure, array $window, ?PayrollRun $forRun = null): array
    {
        $windowFrom = $window['start']->copy()->startOfDay();
        $windowTo = $window['end']->copy()->startOfDay();
        $from = $windowFrom->toDateString();
        $to = $windowTo->toDateString();

        // Attendance (fingerprint import or manual entry) is effective immediately.
        $attendance = Attendance::withoutGlobalScopes()->effective()->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$windowFrom->toDateString(), $windowTo->toDateString()])->get();
        $absenceRecords = $attendance->filter(fn (Attendance $record) => in_array($record->status, ['Absent', 'غياب'], true));

        $approvedLeaves = Leave::withoutGlobalScopes()->with('leaveType')->where('employee_id', $employee->id)->effective()
            ->whereDate('start_date', '<=', $windowTo->toDateString())->whereDate('end_date', '>=', $windowFrom->toDateString())->get();
        $unpaidDates = [];
        if ($this->hrSettings->unpaidLeaveAsDeduction()) {
            foreach ($approvedLeaves as $leave) {
                if (!$this->isUnpaidLeave($leave)) continue;
                foreach ($this->leaveDatesWithin($leave, $windowFrom, $windowTo) as $date) $unpaidDates[$date] = true;
            }
        }
        $excuses = $this->requestsInMonth($employee, ['permission', 'mission'], $from, $to, false);
        $daily = app(EmployeePeriodMetrics::class)->calculate($employee, $windowFrom, $windowTo, $attendance, $approvedLeaves, $excuses);
        // Leave is already excluded from absence; unpaid leave is added exactly once.
        $absenceDays = $daily['absence_days'] + count($unpaidDates);

        $overtime = $this->earnedOvertime($employee, $attendance);

        $otherRequests = $this->requestsInMonth($employee, ['other'], $from, $to, true);
        $otherEarnings = (float) $otherRequests->filter(fn ($request) => ($request->payload['impact_type'] ?? null) === 'earning')->sum(fn ($request) => (float) ($request->payload['amount'] ?? 0));
        $otherDeductions = (float) $otherRequests->filter(fn ($request) => ($request->payload['impact_type'] ?? null) === 'deduction')->sum(fn ($request) => (float) ($request->payload['amount'] ?? 0));

        $financialRequests = $this->requestsInMonth($employee, ['bonus', 'incentive', 'commission', 'profit_share'], $from, $to, false);
        $financialAmounts = collect(['bonus', 'incentive', 'commission', 'profit_share'])
            ->mapWithKeys(fn ($source) => [$source => (float) $financialRequests
                ->filter(fn ($request) => ($request->requestType?->source_key ?: $request->request_type) === $source)
                ->sum(fn ($request) => (float) data_get($request->payload, 'amount', 0))]);

        $penalties = Penalty::withoutGlobalScopes()->with('penaltyType')->where('employee_id', $employee->id)->whereBetween('penalty_date', [$from, $to])
            ->effective()->get();
        $penaltyDays = $penalties->sum(fn (Penalty $penalty) => $penalty->deduction_days ?? $penalty->penaltyType?->default_deduction_days ?? 0);
        $advancePlan = $this->advanceDeduction($employee, $month, (float) $structure['gross'], $forRun);

        return [
            'ot_day_hours' => round($overtime['day_minutes'] / 60, 2), 'ot_night_hours' => round($overtime['night_minutes'] / 60, 2),
            'official_holiday_days' => $overtime['holiday_days'], 'weekly_rest_days' => $overtime['rest_days'],
            'absence_days' => $absenceDays, 'penalty_days' => (float) $penaltyDays,
            'bonus' => $financialAmounts['bonus'], 'incentives' => $financialAmounts['incentive'], 'commission' => $financialAmounts['commission'], 'profit_share' => $financialAmounts['profit_share'],
            'advance_earnings' => $advancePlan['grant_amount'], 'loans' => $advancePlan['amount'], 'advance_details' => $advancePlan['details'],
            'other_earnings' => $otherEarnings, 'other_deductions' => $otherDeductions,
            'approved_attendance_ids' => $attendance->pluck('id')->all(), 'approved_overtime_request_ids' => $overtime['request_ids'], 'approved_other_request_ids' => $otherRequests->pluck('id')->all(),
            'approved_financial_request_ids' => $financialRequests->pluck('id')->all(),
            'absence_attendance_ids' => $absenceRecords->pluck('id')->all(),
            'unpaid_leave_dates' => array_keys($unpaidDates),
            'approved_leave_ids' => $approvedLeaves->pluck('id')->all(), 'approved_penalty_ids' => $penalties->pluck('id')->all(),
        ];
    }

    /** HR requests of the given source types dated inside the month; rejected ones never count. */
    private function requestsInMonth(Employee $employee, array $sources, string $from, string $to, bool $honorPayrollFlag): Collection
    {
        return HrRequest::withoutGlobalScopes()->with('requestType')
            ->where('employee_id', $employee->id)
            ->effective()
            ->where(function ($query) use ($sources) {
                $query->whereIn('request_type', $sources)
                    ->orWhereHas('requestType', fn ($type) => $type->whereIn('source_key', $sources));
            })->get()->filter(function ($request) use ($from, $to, $honorPayrollFlag) {
                $payload = is_array($request->payload) ? $request->payload : [];
                if ($honorPayrollFlag && array_key_exists('affects_payroll', $payload) && !filter_var($payload['affects_payroll'], FILTER_VALIDATE_BOOLEAN)) return false;
                $source = $request->requestType?->source_key ?: $request->request_type;
                if (!in_array($source, ['permission', 'mission'], true)) {
                    $date = $payload['request_date'] ?? $payload['start_date'] ?? $request->created_at?->toDateString();
                    return $date && Carbon::parse($date)->betweenIncluded(Carbon::parse($from), Carbon::parse($to));
                }
                $date = $payload['start_date'] ?? $payload['request_date'] ?? $payload['date'] ?? $request->created_at?->toDateString();
                $end = $payload['end_date'] ?? $date;
                return $date && Carbon::parse($date)->lte(Carbon::parse($to)) && Carbon::parse($end)->gte(Carbon::parse($from));
            })->values();
    }

    private function isUnpaidLeave(Leave $leave): bool
    {
        $type = $leave->leaveType;
        if (!$type) return false;

        return !(bool) $type->is_paid || strtolower(trim((string) $type->policy_code)) === 'unpaid';
    }

    /** @return string[] Y-m-d dates of the leave that fall inside the window (rest days and holidays optionally skipped) */
    private function leaveDatesWithin(Leave $leave, Carbon $windowFrom, Carbon $windowTo): array
    {
        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();
        if ($start->lt($windowFrom)) $start = $windowFrom->copy();
        if ($end->gt($windowTo)) $end = $windowTo->copy();
        $skipRest = $this->hrSettings->excludeRestAndHolidaysFromLeave();

        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($skipRest && ($this->hrSettings->isWeeklyRest($date) || $this->hrSettings->isHoliday($date))) continue;
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * Approved advances start in the month after the request date. Both the
     * advance and the monthly installment are capped at a share of gross salary
     * (a company setting, 10% by default).
     */
    private function advanceDeduction(Employee $employee, Carbon $month, float $gross, ?PayrollRun $forRun = null): array
    {
        $requests = HrRequest::withoutGlobalScopes()->with('requestType')
            ->where('employee_id', $employee->id)
            ->effective()
            ->where(function ($query) {
                $query->where('request_type', 'advance')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'advance'));
            })->get();
        // Canceled runs never paid anything: their records must not count as history.
        $priorRecords = PayrollRecord::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('payroll_month', '<', $month->toDateString())
            ->where(function ($query) {
                $query->whereNull('payroll_run_id')
                    ->orWhereNotIn('payroll_run_id', DB::table('payroll_runs')->select('id')->where('status', 'Canceled'));
            })
            ->get(['payroll_month', 'loans', 'calculation_snapshot']);
        $monthlyCap = round(max(0, $gross) * $this->settings->advanceMaxFraction(), 2);
        $remainingCapacity = $monthlyCap;
        $total = 0.0;
        $grantAmount = 0.0;
        $details = [];
        $paidByRequest = [];
        $legacyRecords = [];

        // Newer records retain the exact request allocation. Older records may
        // only have the aggregate loan value, so collect both formats first.
        foreach ($priorRecords as $record) {
            $recordDetails = (array) data_get($record->calculation_snapshot, 'advance_details', []);
            if ($recordDetails) {
                foreach ($recordDetails as $detail) {
                    $requestId = (int) ($detail['request_id'] ?? 0);
                    if ($requestId > 0) {
                        $paidByRequest[$requestId] = round(
                            ($paidByRequest[$requestId] ?? 0) + (float) ($detail['installment'] ?? 0),
                            2
                        );
                    }
                }
            } else {
                $legacyRecords[] = [
                    'month' => Carbon::parse($record->payroll_month)->startOfMonth(),
                    'amount' => (float) ($record->loans ?? data_get($record->calculation_snapshot, 'inputs.loans', 0)),
                ];
            }
        }

        // Backfill payment history for records created before advance_details
        // was stored: allocate each legacy aggregate to the oldest outstanding advance first.
        $orderedRequests = $requests->sortBy(function ($request) {
            return [
                (string) data_get($request->payload, 'request_date', $request->created_at?->toDateString()),
                (int) $request->id,
            ];
        });
        foreach ($legacyRecords as $legacy) {
            $remainingLegacy = round(max(0, $legacy['amount']), 2);
            if ($remainingLegacy <= 0) continue;
            foreach ($orderedRequests as $request) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $amount = (float) ($payload['amount'] ?? 0);
                $requestDate = $payload['request_date'] ?? $request->created_at?->toDateString();
                if ($amount <= 0 || !$requestDate) continue;
                if (Carbon::parse($requestDate)->startOfMonth()->gte($legacy['month'])) continue;
                $outstanding = round(max(0, $amount - ($paidByRequest[$request->id] ?? 0)), 2);
                $allocated = round(min($outstanding, $remainingLegacy), 2);
                if ($allocated <= 0) continue;
                $paidByRequest[$request->id] = round(($paidByRequest[$request->id] ?? 0) + $allocated, 2);
                $remainingLegacy = round($remainingLegacy - $allocated, 2);
                if ($remainingLegacy <= 0) break;
            }
        }

        // Settlement recoveries collected outside this month's schedule: an advance
        // recovered through an approved/paid final settlement must never be deducted
        // again by a later payroll run. The settlement's own anchor month is excluded:
        // that month's schedule is computed first, then the settlement folds its
        // recovery on top (and re-folds it after every regeneration).
        foreach (Settlement::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['Approved', 'Paid'])
            ->whereDate('anchor_month', '<', $month->copy()->startOfMonth()->toDateString())
            ->get(['advance_recovery_details']) as $settled) {
            foreach ((array) ($settled->advance_recovery_details ?? []) as $recovered) {
                $requestId = (int) ($recovered['request_id'] ?? 0);
                if ($requestId > 0) {
                    $paidByRequest[$requestId] = round(
                        ($paidByRequest[$requestId] ?? 0) + (float) ($recovered['amount'] ?? 0),
                        2
                    );
                }
            }
        }

        if ($forRun) {
            // Transition run: an advance is granted by the run whose exact period contains
            // the hand-over date, and one installment is collected per owned charge month
            // (the monthly cap applies per charged month). Months owned by another run
            // are never double-charged.
            $periodStart = $forRun->period_start->copy()->startOfDay();
            $periodEnd = $forRun->period_end->copy()->startOfDay();
            $chargeMonths = $this->chargeMonthsFor($forRun, $month);
            foreach ($orderedRequests as $request) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $amount = (float) ($payload['amount'] ?? 0);
                $requestDate = $payload['request_date'] ?? $request->created_at?->toDateString();
                if ($amount <= 0 || !$requestDate) continue;
                if (Carbon::parse($requestDate)->betweenIncluded($periodStart, $periodEnd)) {
                    $grantAmount += $amount;
                }
            }
            foreach ($chargeMonths as $chargeMonth) {
                $remainingCapacity = $monthlyCap;
                foreach ($orderedRequests as $request) {
                    $payload = is_array($request->payload) ? $request->payload : [];
                    $amount = (float) ($payload['amount'] ?? 0);
                    $requestDate = $payload['request_date'] ?? $request->created_at?->toDateString();
                    if ($amount <= 0 || !$requestDate) continue;
                    $startMonth = Carbon::parse($requestDate)->startOfMonth()->addMonth();
                    if ($chargeMonth->lt($startMonth)) continue;
                    $paid = (float) ($paidByRequest[$request->id] ?? 0);
                    $remaining = round(max(0, $amount - $paid), 2);
                    if ($remaining <= 0 || $remainingCapacity <= 0) continue;
                    $installment = round(min($remaining, $remainingCapacity), 2);
                    $total += $installment;
                    $remainingCapacity = round($remainingCapacity - $installment, 2);
                    $paidByRequest[$request->id] = round(($paidByRequest[$request->id] ?? 0) + $installment, 2);
                    $details[] = [
                        'request_id' => $request->id,
                        'amount' => $amount,
                        'paid_before' => round($paid, 2),
                        'installment' => $installment,
                        'remaining_after' => round($remaining - $installment, 2),
                        'start_month' => $startMonth->toDateString(),
                        'charge_month' => $chargeMonth->toDateString(),
                    ];
                }
            }

            return ['amount' => round($total, 2), 'grant_amount' => round($grantAmount, 2), 'details' => $details];
        }

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
            $paid = (float) ($paidByRequest[$request->id] ?? 0);
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

    /* ------------------------------------------------------------------ *
     | Overtime (day / night split from the fingerprint times, rest & holiday work)
     * ------------------------------------------------------------------ */

    /**
     * Overtime is earned only when BOTH an overtime request and attendance times
     * exist. Hours outside the shift are split into day and night using the
     * company night window. Work on an official holiday or weekly rest day is
     * paid as whole days (multiplier from settings) instead of hours.
     */
    private function earnedOvertime(Employee $employee, Collection $attendance): array
    {
        $requests = HrRequest::withoutGlobalScopes()->with('requestType')
            ->where('employee_id', $employee->id)
            ->effective()
            ->where(function ($query) {
                $query->where('request_type', 'overtime')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'overtime'));
            })->get();
        $restNeedsRequest = (bool) config('payroll.rest_work_requires_request', true);

        $dayMinutes = 0.0;
        $nightMinutes = 0.0;
        $holidayDays = 0;
        $restDays = 0;
        $requestIds = [];

        foreach ($attendance as $record) {
            if (!$record->attendance_date) continue;
            if (in_array($record->status, ['Absent', 'غياب'], true)) continue;
            $date = $record->attendance_date;
            $covering = $this->coveringOvertimeRequests($requests, $date);
            $isHoliday = $this->hrSettings->isHoliday($date);
            $isRest = $this->hrSettings->isWeeklyRest($date);

            if ($isHoliday || $isRest) {
                if (!$record->check_in || !$record->check_out) continue;
                if ($restNeedsRequest && $covering === []) continue;
                if ($isHoliday) $holidayDays++; else $restDays++;
                foreach ($covering as $item) $requestIds[] = $item[0]->id;
                continue;
            }

            [$dayPotential, $nightPotential] = $this->potentialOvertime($employee, $record);
            $potential = $dayPotential + $nightPotential;
            if ($potential <= 0 || $covering === []) continue;

            // Authorize each overtime period independently. A night request
            // must never be used to pay day overtime (and vice versa).
            // Timed requests earn only their window's overlap with actual
            // outside-shift presence (partial overlap earns the partial
            // duration). Untimed legacy requests earn nothing; Amer confirmed
            // they are test data removed by the scoped cleanup command.
            $authorizedDay = 0.0;
            $authorizedNight = 0.0;
            $segments = null;
            foreach ($covering as [$request, $minutes]) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $period = data_get($payload, 'overtime_period', 'day');
                $from = OvertimeWindowMatcher::timeToMinutes($payload['from_time'] ?? null);
                $to = OvertimeWindowMatcher::timeToMinutes($payload['to_time'] ?? null);
                if ($from === null || $to === null) continue;
                $segments ??= OvertimeWindowMatcher::outsideShiftSegments(
                    $this->minutesOf((string) $record->check_in),
                    $this->minutesOf((string) $record->check_out),
                    $this->minutesOf((string) ($employee->shift_start ?: $this->hrSettings->start())),
                    $this->minutesOf((string) ($employee->shift_end ?: $this->hrSettings->end())),
                );
                $minutes = OvertimeWindowMatcher::authorizedMinutes($segments, $from, $to, $minutes);
                if ($minutes <= 0) continue;
                if ($period === 'night') {
                    $authorizedNight += $minutes;
                } else {
                    $authorizedDay += $minutes;
                }
            }

            $earnedDay = min($dayPotential, $authorizedDay);
            $earnedNight = min($nightPotential, $authorizedNight);
            if ($earnedDay <= 0 && $earnedNight <= 0) continue;

            $dayMinutes += $earnedDay;
            $nightMinutes += $earnedNight;
            foreach ($covering as [$request]) {
                $period = data_get($request->payload, 'overtime_period', 'day');
                if (($period === 'night' && $earnedNight > 0) || ($period !== 'night' && $earnedDay > 0)) {
                    $requestIds[] = $request->id;
                }
            }
        }

        return [
            'day_minutes' => $dayMinutes, 'night_minutes' => $nightMinutes,
            'holiday_days' => $holidayDays, 'rest_days' => $restDays,
            'request_ids' => array_values(array_unique($requestIds)),
        ];
    }

    /** @return array<int, array{0: HrRequest, 1: float}> requests covering the date and their authorized minutes per day */
    private function coveringOvertimeRequests(Collection $requests, Carbon $date): array
    {
        $covering = [];
        foreach ($requests as $request) {
            $payload = is_array($request->payload) ? $request->payload : [];
            $start = $payload['start_date'] ?? $payload['request_date'] ?? $request->created_at?->toDateString();
            if (!$start) continue;
            $startDate = Carbon::parse($start)->startOfDay();
            $endDate = Carbon::parse($payload['end_date'] ?? $start)->startOfDay();
            if (!$date->betweenIncluded($startDate, $endDate)) continue;
            $hours = (float) ($payload['hours'] ?? 0);
            if ($hours <= 0) continue;
            $days = max(1, (int) round($startDate->diffInDays($endDate)) + 1);
            $covering[] = [$request, ($hours / $days) * 60];
        }

        return $covering;
    }

    /** @return array{0: float, 1: float} potential [day, night] overtime minutes outside the shift */
    private function potentialOvertime(Employee $employee, Attendance $record): array
    {
        if (!$record->check_in || !$record->check_out) return [0.0, 0.0];
        $in = $this->minutesOf((string) $record->check_in);
        $out = $this->minutesOf((string) $record->check_out);
        $shiftStart = $this->minutesOf((string) ($employee->shift_start ?: $this->hrSettings->start()));
        $shiftEnd = $this->minutesOf((string) ($employee->shift_end ?: $this->hrSettings->end()));
        if ($shiftEnd <= $shiftStart) $shiftEnd += 1440;
        if ($out <= $in) $out += 1440;

        $segments = [];
        if ($in < $shiftStart) $segments[] = [$in, min($shiftStart, $out)];
        if ($out > $shiftEnd) $segments[] = [max($shiftEnd, $in), $out];

        $night = 0.0;
        $total = 0.0;
        foreach ($segments as [$a, $b]) {
            if ($b <= $a) continue;
            $total += $b - $a;
            $night += $this->nightOverlap((float) $a, (float) $b);
        }

        return [max(0.0, $total - $night), $night];
    }

    /** Minutes of [a, b] (minutes from midnight, may exceed 1440) that fall inside the night window. */
    private function nightOverlap(float $a, float $b): float
    {
        $nightStart = $this->minutesOf($this->hrSettings->nightStart());
        $nightEnd = $this->minutesOf($this->hrSettings->nightEnd());
        if ($nightEnd <= $nightStart) $nightEnd += 1440;

        $overlap = 0.0;
        for ($k = -1; $k <= 1; $k++) {
            $overlap += max(0.0, min($b, $nightEnd + 1440 * $k) - max($a, $nightStart + 1440 * $k));
        }

        return $overlap;
    }

    private function minutesOf(string $time): int
    {
        $parts = explode(':', trim($time));

        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }

    /* ------------------------------------------------------------------ *
     | Approval, locking, automatic month close, deletion
     * ------------------------------------------------------------------ */

    public function monthHasEnded(Carbon $month): bool
    {
        return Carbon::today()->gt($month->copy()->endOfMonth()->startOfDay());
    }

    public function approve(PayrollRun $run, bool $confirmSkipped = false, bool $automatic = false): PayrollRun
    {
        return DB::transaction(function () use ($run, $confirmSkipped, $automatic) {
            $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            abort_if($run->status !== 'Draft', 422, 'Only draft payroll runs can be approved.');
            abort_if(
                !$automatic && !$this->monthHasEnded($run->payroll_month),
                422,
                'لا يمكن اعتماد مسير الشهر قبل انتهائه. يتم اعتماد المسير وقفله تلقائيًا في آخر يوم من الشهر.'
            );
            abort_if(
                PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->doesntExist(),
                422,
                'لا توجد سجلات رواتب في هذا المسير.'
            );

            $report = $this->reconciliationReport($run);
            if (!$automatic && count($report['skipped']) > 0 && !$confirmSkipped) {
                abort(422, 'يوجد ' . count($report['skipped']) . ' موظف لم يُنشأ لهم سجل راتب. راجع القائمة وأكّد الاعتماد صراحة إذا كان ذلك مقصودًا.');
            }

            $actor = auth()->id();
            $period = CompanyContext::run((int) $run->company_id, function () use ($run) {
                // A run already carrying exact period dates (transition or cycle-pinned draft) keeps them.
                if ($run->period_start && $run->period_end) {
                    return ['start' => $run->period_start->copy()->startOfDay(), 'end' => $run->period_end->copy()->startOfDay()];
                }
                return $this->hrSettings->payrollPeriod($run->payroll_month->copy()->startOfMonth());
            });
            $run->update(['period_start' => $period['start']->toDateString(), 'period_end' => $period['end']->toDateString(), 'status' => 'Approved', 'approved_by' => $actor, 'approved_at' => now(), 'skipped_report' => $report, 'closes_at' => $run->closes_at ?? $this->closesAtFor($period['end'], $this->hrSettings->attendanceCorrectionDays())->toDateString()]);
            PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->whereNotIn('status', self::PAID_STATUSES)
                ->update(['status' => 'Approved', 'approved_by' => $actor, 'approved_at' => now()]);
            ActivityLog::record(
                $automatic ? 'payroll_run_auto_approve' : 'payroll_run_approve',
                $automatic ? 'Payroll run approved automatically at month end.' : 'Payroll run approved.',
                $run, ['status' => 'Draft'], ['status' => 'Approved', 'skipped' => count($report['skipped'])], 'payroll'
            );

            return $run->fresh();
        });
    }

    public function lock(PayrollRun $run, bool $automatic = false): PayrollRun
    {
        return DB::transaction(function () use ($run, $automatic) {
            $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            abort_if($run->status !== 'Approved', 422, 'Only approved payroll runs can be locked.');
            abort_if(
                !$automatic && !$this->monthHasEnded($run->payroll_month),
                422,
                'لا يمكن قفل مسير الشهر قبل انتهائه. يتم قفله تلقائيًا في آخر يوم من الشهر.'
            );

            $run->update(['status' => 'Locked', 'locked_by' => auth()->id(), 'locked_at' => now()]);
            PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->update(['status' => 'Locked', 'is_locked' => true]);
            ActivityLog::record(
                $automatic ? 'payroll_run_auto_lock' : 'payroll_run_lock',
                $automatic ? 'Payroll run locked automatically at month end.' : 'Payroll run locked with immutable snapshots.',
                $run, ['status' => 'Approved'], ['status' => 'Locked'], 'payroll'
            );

            return $run->fresh();
        });
    }

    /** Generate, approve and lock one company's payroll for a month without any user (month-end job). */
    public function closeMonth(int $companyId, Carbon $month): ?PayrollRun
    {
        $month = $month->copy()->startOfMonth();

        return CompanyContext::run($companyId, function () use ($companyId, $month) {
            $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('payroll_month', $month->toDateString())->first();
            if ($run && in_array($run->status, ['Locked', 'Paid'], true)) return $run;

            if (!$run || $run->status !== 'Approved') {
                $run = $this->generate($month, null, $companyId);
                if (PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->doesntExist()) {
                    // Nobody to pay (company without salary structures): leave no empty run behind.
                    $run->delete();
                    return null;
                }
            }

            return $this->closeRun($run, true);
        });
    }

    /** The date a period finally closes: its last day plus the full correction grace. */
    public function closesAtFor(Carbon $periodEnd, int $graceDays): Carbon
    {
        // Same schedule as the attendance archive: the full grace days stay correctable,
        // the run closes on the first day after them (period end + grace + 1).
        return $periodEnd->copy()->startOfDay()->addDays(max(0, $graceDays) + 1);
    }

    /**
     * The single lock path for every period, monthly or transition, automatic or manual:
     * freeze the period's attendance first, recalculate a draft from the frozen data,
     * approve, then lock the run and its records, and refresh the saved monthly summary
     * with the final numbers. Idempotent: an already-final run is returned untouched.
     * A period that has not ended is never closed.
     */
    /**
     * The period a run actually covers: its stored dates when present, otherwise the
     * cycle snapshotted at creation, and only for pre-snapshot rows the live settings.
     *
     * @return array{start: Carbon,end: Carbon}
     */
    public function periodForRun(PayrollRun $run): array
    {
        if ($run->period_start && $run->period_end) {
            return ['start' => $run->period_start->copy()->startOfDay(), 'end' => $run->period_end->copy()->startOfDay()];
        }
        $month = $run->payroll_month->copy()->startOfMonth();
        $snapshot = (array) ($run->settings_snapshot ?? []);
        $startDay = (int) data_get($snapshot, 'period.start_day', 0);
        $endDay = (int) data_get($snapshot, 'period.end_day', 0);
        if ($startDay >= 1 && $endDay >= 1) {
            return $this->hrSettings->payrollPeriodForDays($month, $startDay, $endDay);
        }

        return $this->hrSettings->payrollPeriod($month);
    }

    public function closeRun(PayrollRun $run, bool $automatic = false): PayrollRun
    {
        $companyId = (int) $run->company_id;

        return CompanyContext::run($companyId, function () use ($run, $companyId, $automatic) {
            return DB::transaction(function () use ($run, $companyId, $automatic) {
                // One writer per company: settings saves and attendance writers queue here.
                app(AttendancePeriodArchiveService::class)->lockCompanyForWrite($companyId);
                $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
                if (in_array($run->status, ['Locked', 'Paid'], true)) {
                    return $run; // idempotent: already final
                }
                abort_if($run->status === 'Canceled', 422, 'لا يمكن قفل مسير ملغي.');

                $period = $this->periodForRun($run);
                abort_if($period['end']->gte(Carbon::today()), 422, 'لا يمكن قفل فترة لم تنتهِ بعد.');

                // 1) Freeze the period's attendance before any recalculation.
                $archives = app(AttendancePeriodArchiveService::class);
                if ($archive = $archives->archivePeriod($companyId, $period['start'], $period['end'])) {
                    $archives->close($archive);
                }

                // 2) Recalculate a draft from the frozen attendance, then approve.
                if ($run->status === 'Draft') {
                    $run = $this->generate($run->payroll_month->copy(), null, $companyId, $run->is_transition ? $run : null);
                    if (PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->exists()) {
                        $run = $this->approve($run, false, true);
                    } else {
                        // Nothing to pay: lock the empty run as-is so the period is final.
                        $run->update(['status' => 'Locked', 'locked_by' => auth()->id(), 'locked_at' => now()]);
                        ActivityLog::record('payroll_run_auto_lock', 'Empty payroll run locked automatically at period close.', $run, ['status' => 'Draft'], ['status' => 'Locked'], 'payroll');
                    }
                }

                // 3) Lock the run and its records.
                if ($run->status === 'Approved') {
                    $run = $this->lock($run, true);
                }

                // 4) Refresh the saved monthly summary with the final numbers.
                // The summary belongs to the month containing the period END: for a
                // transition run that is the charge month, not the anchor date stored
                // in payroll_month. The company is passed explicitly because the
                // scheduled close runs without an authenticated web user.
                try {
                    app(JobDataController::class)->captureSnapshot($period['end']->copy()->startOfMonth(), auth()->id(), $companyId);
                } catch (\Throwable $e) {
                    report($e); // the lock itself already succeeded; the summary can be recaptured
                }

                return $run->fresh();
            });
        });
    }

    public function destroy(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = PayrollRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            $records = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id);
            $summary = [
                'records' => $records->count(),
                'net_total' => round((float) $records->sum('net_salary'), 2),
                'gross_total' => round((float) $records->sum('gross_salary'), 2),
                'employee_ids' => $records->pluck('employee_id')->all(),
            ];
            $snapshot = array_merge($run->toArray(), ['deleted_summary' => $summary]);
            $records->delete();
            $run->delete();
            ActivityLog::record('payroll_run_delete', 'Payroll run deleted.', null, $snapshot, null, 'payroll');
        });
    }

    /** @deprecated use destroy(); kept for older callers. */
    public function destroyDraft(PayrollRun $run): void
    {
        $this->destroy($run);
    }

    /* ------------------------------------------------------------------ *
     | Statutory values and proration
     * ------------------------------------------------------------------ */

    /**
     * Insurance and the fixed part of income tax are approved with the salary
     * structure; the run copies them and adds the month's variable entries. The
     * extra tax on taxable variable earnings (computed by the calculator) is
     * added on top of the structure tax.
     */
    public function applyStructureStatutoryValues(array $calculation, Salary $salary, bool $insuranceDue = true, int $insuranceMonths = 1): array
    {
        $insuranceMonths = $insuranceDue ? max(1, $insuranceMonths) : 0;
        $insuranceWage = $insuranceDue ? (float) ($salary->applied_insurance_wage ?? $salary->insurance_wage ?? 0) : 0.0;
        // A run charges one full monthly insurance per calendar month it owns (never prorated).
        $employeeInsurance = $insuranceDue ? (float) ($salary->employee_insurance ?? 0) * $insuranceMonths : 0.0;
        $structureTax = (float) ($salary->income_tax ?? 0);
        $variableTax = (float) ($calculation['income_tax_variable'] ?? 0);
        $incomeTax = round($structureTax + $variableTax, 2);
        $martyrsFund = (float) ($salary->martyrs_fund ?? 0);
        $employerInsurance = $insuranceDue ? (float) ($salary->employer_insurance ?? 0) * $insuranceMonths : 0.0;
        $deductions = $employeeInsurance + $incomeTax + $martyrsFund
            + (float) $calculation['absence_deduction'] + (float) $calculation['penalty_deduction']
            + (float) $calculation['loans'] + (float) $calculation['other_deductions'];

        return array_merge($calculation, [
            'insurance_wage' => $insuranceWage,
            'employee_insurance' => $employeeInsurance,
            'income_tax_structure' => $structureTax,
            'income_tax' => $incomeTax,
            'martyrs_fund' => $martyrsFund,
            'employer_insurance' => $employerInsurance,
            'employee_cost' => !$insuranceDue
                ? (float) $calculation['gross']
                : ($insuranceMonths === 1 && $salary->employee_cost !== null
                    ? (float) $salary->employee_cost
                    : (float) $calculation['gross'] + $employerInsurance),
            'monthly_deductions' => round($deductions, 2),
            'net_monthly' => round((float) $calculation['entitlements'] - $deductions, 2),
        ]);
    }

    /**
     * Prorate a partial month.
     *  - Prorated: basic + allowances, the structure income tax and the martyrs fund.
     *  - Never prorated: social insurance (paid in full), absence and penalty deductions,
     *    advances, other deductions and every variable earning (overtime, bonuses...).
     */
    public function applyProration(array $calculation, float $factor, bool $allowOverFullMonth = false): array
    {
        // Transition runs pay by actual days even beyond a full month; monthly runs stay capped.
        $factor = $allowOverFullMonth ? max(0, $factor) : min(1, max(0, $factor));
        if ($factor >= 1 && (!$allowOverFullMonth || abs($factor - 1) < 0.000001)) {
            return $calculation + [
                'proration_factor' => 1.0,
                'monthly_total' => round((float) ($calculation['monthly_total'] ?? $calculation['gross']), 2),
                'employee_cost' => round((float) $calculation['entitlements'] + (float) $calculation['employer_insurance'], 2),
            ];
        }

        // The accrued amount is based on the full monthly gross salary
        // (basic salary plus fixed allowances), divided over 30 days.
        $fixedEntitlements = (float) ($calculation['gross'] ?? ((float) $calculation['basic_salary'] + (float) $calculation['allowances']));
        $variableEntitlements = (float) $calculation['entitlements'] - $fixedEntitlements;
        $proratedDeductions = (float) ($calculation['income_tax_structure'] ?? 0) + (float) ($calculation['martyrs_fund'] ?? 0);
        $fullDeductions = max(0, (float) $calculation['monthly_deductions'] - $proratedDeductions);
        $entitlements = round(($fixedEntitlements * $factor) + $variableEntitlements, 2);
        $monthlyTotal = round(($fixedEntitlements * $factor)
            + (float) ($calculation['overtime_amount'] ?? 0)
            + (float) ($calculation['bonus'] ?? 0)
            + (float) ($calculation['incentives'] ?? 0)
            + (float) ($calculation['commission'] ?? 0)
            + (float) ($calculation['profit_share'] ?? 0)
            + (float) ($calculation['other_earnings'] ?? 0)
            + (float) ($calculation['advance_earnings'] ?? 0), 2);
        $deductions = round(($proratedDeductions * $factor) + $fullDeductions, 2);
        $proratedBasic = round((float) $calculation['basic_salary'] * $factor, 2);
        $proratedAllowances = round((float) $calculation['allowances'] * $factor, 2);
        $proratedStructureTax = round((float) ($calculation['income_tax_structure'] ?? 0) * $factor, 2);
        $variableTax = (float) ($calculation['income_tax_variable'] ?? 0);

        return array_merge($calculation, [
            // Persist the values actually earned during the partial period so
            // the payroll table and payslip details match the net calculation.
            'basic_salary' => $proratedBasic,
            'allowances' => $proratedAllowances,
            'housing_allowance' => round((float) ($calculation['housing_allowance'] ?? 0) * $factor, 2),
            'transportation_allowance' => round((float) ($calculation['transportation_allowance'] ?? 0) * $factor, 2),
            'meal_allowance' => round((float) ($calculation['meal_allowance'] ?? 0) * $factor, 2),
            'clothing_allowance' => round((float) ($calculation['clothing_allowance'] ?? 0) * $factor, 2),
            'income_tax' => round($proratedStructureTax + $variableTax, 2),
            'martyrs_fund' => round((float) ($calculation['martyrs_fund'] ?? 0) * $factor, 2),
            'entitlements' => $entitlements,
            'to_date_entitlement' => $proratedBasic,
            'monthly_total' => $monthlyTotal,
            'monthly_deductions' => $deductions,
            'net_monthly' => round($entitlements - $deductions, 2),
            'employee_cost' => round($entitlements + (float) $calculation['employer_insurance'], 2),
            'proration_factor' => $factor,
        ]);
    }

    /**
     * Period the employee is paid for in this month. For the current month the
     * period ends today so users can follow the amount accrued so far; past
     * months always run to month end. Days are counted with integer logic only.
     */
    public function serviceWindow(Employee $employee, Carbon $month, ?array $periodOverride = null): ?array
    {
        $period = $this->periodFor($month, $periodOverride);
        $periodStart = $period['start']->copy()->startOfDay();
        $periodEnd = $period['end']->copy()->startOfDay();
        $today = Carbon::today()->startOfDay();
        $monthStart = $periodStart->copy();
        $calendarMonthEnd = $periodEnd->copy();
        // The current period is accrued only through today; a completed
        // cross-month period remains complete even if its anchor month is current.
        $monthEnd = $today->betweenIncluded($periodStart, $periodEnd)
            ? $today
            : $periodEnd->copy();
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
            // No termination date means there is no reliable period to include. The run
            // pays him when its actual payroll period contains the termination date;
            // the anchor calendar month is irrelevant for cross-month periods (26th-25th).
            if (!$terminationDate || !$terminationDate->betweenIncluded($periodStart, $periodEnd)) return null;
        }
        $returnDate = !$terminal && $employee->return_date
            ? Carbon::parse($employee->return_date)->startOfDay()
            : null;
        $departureLastDay = null;
        if ($returnDate) {
            // After a rehire, the departure period of a service break stays
            // payable through the last working day recorded by its approved/paid
            // settlement; periods fully inside the break pay nothing.
            $departureLastDay = \App\Models\Settlement::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['Approved', 'Paid'])
                ->whereBetween('last_working_day', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->max('last_working_day');
            $departureLastDay = $departureLastDay ? Carbon::parse($departureLastDay)->startOfDay() : null;
            if (!$departureLastDay && $returnDate->greaterThan($periodEnd)) {
                $earlierDeparture = \App\Models\Settlement::withoutGlobalScopes()
                    ->where('company_id', $employee->company_id)
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', ['Approved', 'Paid'])
                    ->whereDate('last_working_day', '<', $periodStart->toDateString())
                    ->exists();
                if ($earlierDeparture) return null; // period lies fully inside the service break
            }
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

        $basis = $this->settings->prorationDays();
        if ($returnDate && $departureLastDay && $returnDate->greaterThan($departureLastDay) && $returnDate->lessThanOrEqualTo($end)) {
            // Same-period return: pay the departure segment through the last
            // working day plus the return segment from the effective return date.
            $days = PayrollProration::paidDays($start, $departureLastDay, $basis)
                + PayrollProration::paidDays($returnDate, $end, $basis);
        } else {
            if ($departureLastDay && $departureLastDay->lessThan($end)) {
                $end = $departureLastDay->copy();
            }
            if ($returnDate && !$departureLastDay && $returnDate->greaterThan($start)) {
                $start = $returnDate->copy();
            }
            if ($start->greaterThan($end)) return null;
            $days = PayrollProration::paidDays($start, $end, $basis);
        }

        return ['start' => $start, 'end' => $end, 'days' => $days, 'factor' => $periodOverride ? max(0, $days / $basis) : min(1, $days / $basis)];
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

    /**
     * Calendar months whose statutory monthly charges (insurance, advance installments)
     * belong to this run: every month whose first day falls inside the run's period.
     * A monthly run always owns exactly its payroll month; a transition run owns the
     * months bridged by its exact gap period (zero when the gap fits inside one month).
     * The period chain is contiguous and non-overlapping, so each month is charged once.
     *
     * @return array<int, Carbon> month-start dates
     */
    private function chargeMonthsFor(PayrollRun $run, Carbon $month): array
    {
        if (!$run->is_transition) {
            return [$month->copy()->startOfMonth()];
        }
        $start = $run->period_start->copy()->startOfDay();
        $end = $run->period_end->copy()->startOfDay();
        $first = $start->copy()->startOfMonth();
        if ($first->lt($start)) $first->addMonthNoOverflow();
        $months = [];
        for ($cursor = $first; $cursor->lte($end); $cursor = $cursor->copy()->addMonthNoOverflow()) {
            $months[] = $cursor->copy();
        }

        return $months;
    }

    /** Employees hired after the 1st of a month pay no social insurance for that month. */
    private function insuranceIsDueForMonth(Employee $employee, Carbon $month): bool
    {
        if (!$employee->hire_date) return true;

        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        return !($hireDate->isSameMonth($month) && !$hireDate->isSameDay($month->copy()->startOfMonth()));
    }
}
