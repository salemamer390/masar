<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\PayrollRecord;
use App\Models\PayrollRun;
use App\Models\Salary;
use App\Models\Settlement;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Final-settlement lifecycle: Draft -> Approved -> Paid (final). Draft can be canceled.
 *
 * The settlement never invents amounts on its own: leave payout and advance recovery
 * stay "pending" until a reviewer picks an explicit mode, and approval is blocked
 * while any of them is unresolved. Reconciliation always respects payroll runs that
 * are already Approved/Locked/Paid: when the owning period has no editable Draft run
 * the settlement is paid standalone instead of touching payroll.
 */
class SettlementService
{
    public const TERMINATED_STATUSES = ['مستقيل', 'مفصول', 'منتهي العقد', 'منتهي الخدمة'];

    public function __construct(
        private readonly PayrollRunService $payrollRuns,
        private readonly PayrollSettingsService $settings,
        private readonly HrOperationalSettingsService $operational,
        private readonly LeaveBalanceService $leaveBalances,
    ) {}

    public static function hasActiveSettlement(int $employeeId): bool
    {
        return Settlement::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereIn('status', Settlement::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * Create the Draft snapshot for an employee leaving service.
     * Aborts 422 when another settlement is already in flight.
     */
    public function start(Employee $employee, array $data): Settlement
    {
        abort_if(self::hasActiveSettlement($employee->id), 422, 'توجد تسوية نشطة لهذا الموظف بالفعل. أكملها أو ألغِها أولاً.');

        $companyId = (int) $employee->company_id;

        return CompanyContext::run($companyId, function () use ($employee, $data, $companyId) {
            $lastDay = Carbon::parse($data['last_working_day'])->startOfDay();
            $anchor = $this->operational->payrollMonthForDate($lastDay);
            $period = $this->operational->payrollPeriod($anchor);
            $periodStart = $period['start'];
            $periodEnd = $period['end'];

            $balance = $this->leaveBalances->balance($employee, $lastDay->copy());
            $leaveDays = round((float) ($balance['remaining'] ?? 0), 2);
            $outstanding = $this->outstandingAdvances($employee->id, $companyId);

            $settlement = DB::transaction(function () use ($employee, $data, $companyId, $lastDay, $anchor, $periodStart, $periodEnd, $leaveDays, $outstanding) {
                $settlement = Settlement::create([
                    'company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'reason' => $data['reason'],
                    'last_working_day' => $lastDay,
                    'status' => 'Draft',
                    'anchor_month' => $anchor->toDateString(),
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'leave_balance_days' => $leaveDays,
                    'leave_payout_mode' => $leaveDays > 0 ? 'pending' : 'skip',
                    'advance_outstanding' => $outstanding,
                    'advance_recovery_mode' => $outstanding > 0.004 ? 'pending' : 'scheduled',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                ActivityLog::record(
                    'settlement_start',
                    "بدء تسوية ترك خدمة: {$employee->full_name} ({$settlement->getReasonLabelAttribute()})",
                    $settlement,
                    null,
                    ['settlement_id' => $settlement->id]
                );

                return $settlement;
            });

            return $settlement;
        });
    }

    /** Update reviewer decisions and editable amounts while the settlement is a Draft. */
    public function updateDraft(Settlement $settlement, array $data): Settlement
    {
        abort_if($settlement->status !== 'Draft', 422, 'لا يمكن تعديل التسوية بعد اعتمادها.');

        $settlement->fill(collect($data)->only([
            'leave_payout_mode', 'leave_payout_amount', 'advance_recovery_mode',
            'gratuity_amount', 'manual_earnings', 'manual_deductions', 'notes',
        ])->toArray());
        $settlement->save();

        ActivityLog::record('settlement_update', "تعديل تسوية: {$settlement->employee?->full_name}", $settlement, null, ['settlement_id' => $settlement->id]);

        return $settlement;
    }

    /** Leave payout for the chosen mode: balance days x daily basis of the latest salary. */
    public function leavePayoutFor(Settlement $settlement, string $mode): float
    {
        if (! in_array($mode, ['basic', 'gross'], true) || (float) $settlement->leave_balance_days <= 0) {
            return 0.0;
        }

        return CompanyContext::run((int) $settlement->company_id, function () use ($settlement, $mode) {
            $salary = Salary::withoutGlobalScopes()
                ->where('employee_id', $settlement->employee_id)
                ->where('status', 'Active')
                ->whereDate('effective_from', '<=', $settlement->last_working_day)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
            if (! $salary) {
                return 0.0;
            }
            $basis = $mode === 'basic' ? (float) $salary->basic_salary : (float) ($salary->gross_salary ?? $salary->basic_salary);
            $perDay = $basis / $this->settings->prorationDays();

            return round((float) $settlement->leave_balance_days * $perDay, 2);
        });
    }

    /**
     * Approve: block on unresolved decisions, compute final amounts, mark the
     * employee terminated, then reconcile with payroll.
     */
    public function approve(Settlement $settlement): Settlement
    {
        abort_if($settlement->status !== 'Draft', 422, 'لا يمكن اعتماد التسوية إلا من حالة المسودة.');
        $pending = $settlement->pendingDecisions();
        abort_if($pending !== [], 422, 'لا يمكن اعتماد التسوية قبل تحديد طريقة صرف رصيد الإجازات واسترداد السلف.');

        $companyId = (int) $settlement->company_id;

        return CompanyContext::run($companyId, function () use ($settlement, $companyId) {
            return DB::transaction(function () use ($settlement, $companyId) {
                // The employee must be terminal BEFORE payroll touches the period so
                // the service window closes on the last working day.
                $this->terminateEmployee($settlement);

                [$reconciliation, $run, $record] = $this->resolveRunAndRecord($settlement, $companyId);

                // Full recovery means everything still open beyond what this period's
                // record already deducts through its scheduled installments.
                $scheduledLoans = $record ? (float) $record->loans : 0.0;
                $recovery = $settlement->advance_recovery_mode === 'full'
                    ? round(max(0.0, (float) $settlement->advance_outstanding - $scheduledLoans), 2)
                    : 0.0;
                $recoveryDetails = $recovery > 0.004
                    ? $this->allocateRecovery($settlement, $recovery)
                    : null;

                // The payout amount is always the reviewer's explicit entry; the
                // basic/gross figures shown in the UI are advisory previews only,
                // never a silent formula.
                $leavePayout = $settlement->leave_payout_mode === 'pay'
                    ? round((float) $settlement->leave_payout_amount, 2)
                    : 0.0;
                $earnings = round($leavePayout + (float) $settlement->gratuity_amount + (float) $settlement->manual_earnings, 2);
                $deductions = round($recovery + (float) $settlement->manual_deductions, 2);

                $settlement->forceFill([
                    'leave_payout_amount' => $leavePayout,
                    'advance_recovery_amount' => $recovery,
                    'advance_recovery_details' => $recoveryDetails,
                    'total_earnings' => $earnings,
                    'total_deductions' => $deductions,
                    'net_amount' => round($earnings - $deductions, 2),
                    'reconciliation' => $reconciliation,
                    'payroll_run_id' => $run?->id,
                    'payroll_record_id' => $record?->id,
                    'status' => 'Approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ])->save();

                if ($reconciliation === 'draft_run' && $record) {
                    $this->payrollRuns->applySettlementToRecord($record, $settlement);
                }

                ActivityLog::record(
                    'settlement_approve',
                    "اعتماد تسوية ترك خدمة: {$settlement->employee?->full_name} — صافي التسوية {$settlement->net_amount}",
                    $settlement,
                    null,
                    ['settlement_id' => $settlement->id, 'reconciliation' => $reconciliation]
                );

                return $settlement;
            });
        });
    }

    /** Paid is final: nothing about the settlement can change after this. */
    public function markPaid(Settlement $settlement): Settlement
    {
        abort_if($settlement->status !== 'Approved', 422, 'لا يمكن صرف التسوية إلا بعد اعتمادها.');

        $settlement->forceFill([
            'status' => 'Paid',
            'paid_by' => auth()->id(),
            'paid_at' => now(),
        ])->save();

        ActivityLog::record('settlement_pay', "صرف تسوية ترك خدمة: {$settlement->employee?->full_name}", $settlement, null, ['settlement_id' => $settlement->id]);

        return $settlement;
    }

    public function cancel(Settlement $settlement): Settlement
    {
        abort_if($settlement->status !== 'Draft', 422, 'لا يمكن إلغاء التسوية إلا وهي مسودة. أضف تسوية جديدة بدلاً منها.');

        $settlement->forceFill([
            'status' => 'Canceled',
            'canceled_by' => auth()->id(),
            'canceled_at' => now(),
        ])->save();

        ActivityLog::record('settlement_cancel', "إلغاء تسوية ترك خدمة: {$settlement->employee?->full_name}", $settlement, null, ['settlement_id' => $settlement->id]);

        return $settlement;
    }

    /**
     * Outstanding advances, matching HrRequestController::outstandingAdvancesFor:
     * effective advance requests minus installments already deducted on payroll
     * records and minus recoveries recorded by approved/paid settlements.
     */
    public function outstandingAdvances(int $employeeId, int $companyId): float
    {
        $requests = HrRequest::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->effective()
            ->where(function ($query) {
                $query->where('request_type', 'advance')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'advance'));
            })
            ->orderBy('id')
            ->get(['id', 'payload']);

        $total = (float) $requests->sum(fn ($r) => (float) data_get($r->payload, 'amount', 0));
        if ($total <= 0) {
            return 0.0;
        }

        $paid = $this->advancePaidByRequest($employeeId, $companyId);

        $outstanding = 0.0;
        foreach ($requests as $request) {
            $amount = (float) data_get($request->payload, 'amount', 0);
            $outstanding += max(0.0, $amount - (float) ($paid[$request->id] ?? 0));
        }

        return round($outstanding, 2);
    }

    /** Map of advance request id => amount already recovered (payroll installments + settlement recoveries). */
    private function advancePaidByRequest(int $employeeId, int $companyId): array
    {
        $paid = [];

        $records = PayrollRecord::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'Canceled'))
            ->get(['calculation_snapshot']);
        foreach ($records as $record) {
            $details = (array) data_get($record->calculation_snapshot, 'advance_details', [])
                ?: (array) data_get($record->calculation_snapshot, 'inputs.advance_details', []);
            foreach ($details as $detail) {
                $rid = (int) ($detail['request_id'] ?? 0);
                if ($rid > 0) {
                    $paid[$rid] = ($paid[$rid] ?? 0.0) + (float) ($detail['installment'] ?? 0);
                }
            }
        }

        $settled = Settlement::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->whereIn('status', ['Approved', 'Paid'])
            ->get(['advance_recovery_details']);
        foreach ($settled as $s) {
            foreach ((array) ($s->advance_recovery_details ?? []) as $detail) {
                $rid = (int) ($detail['request_id'] ?? 0);
                if ($rid > 0) {
                    $paid[$rid] = ($paid[$rid] ?? 0.0) + (float) ($detail['amount'] ?? 0);
                }
            }
        }

        return $paid;
    }

    /** Oldest-first allocation of the recovered amount across requests still open. */
    private function allocateRecovery(Settlement $settlement, float $recovery): array
    {
        $requests = HrRequest::withoutGlobalScopes()
            ->where('employee_id', $settlement->employee_id)
            ->where('company_id', $settlement->company_id)
            ->effective()
            ->where(function ($query) {
                $query->where('request_type', 'advance')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'advance'));
            })
            ->orderBy('id')
            ->get(['id', 'payload']);

        $paid = $this->advancePaidByRequest($settlement->employee_id, (int) $settlement->company_id);

        $details = [];
        $remaining = $recovery;
        foreach ($requests as $request) {
            if ($remaining <= 0.004) {
                break;
            }
            $open = max(0.0, (float) data_get($request->payload, 'amount', 0) - (float) ($paid[$request->id] ?? 0));
            if ($open <= 0.004) {
                continue;
            }
            $take = round(min($open, $remaining), 2);
            $details[] = ['request_id' => $request->id, 'amount' => $take];
            $remaining = round($remaining - $take, 2);
        }

        return $details;
    }

    /**
     * Apply the employee's terminal status BEFORE payroll touches the period so the
     * service window closes on the last working day and salary prorates correctly.
     */
    private function terminateEmployee(Settlement $settlement): void
    {
        $employee = $settlement->employee()->withoutGlobalScopes()->first();
        abort_if(! $employee, 422, 'الموظف غير موجود.');

        $status = EmployeeStatus::withoutGlobalScopes()->firstOrCreate(['name' => 'منتهي الخدمة']);

        $employee->forceFill([
            'status_id' => $status->id,
            'termination_type' => $settlement->reason,
            'termination_date' => $settlement->last_working_day,
        ])->save();
    }

    /**
     * Locate (or create) the payroll run and record that own the settlement's
     * period, without ever modifying a run that is not a Draft.
     * Returns [mode, ?PayrollRun, ?PayrollRecord].
     */
    private function resolveRunAndRecord(Settlement $settlement, int $companyId): array
    {
        $anchor = Carbon::parse($settlement->anchor_month);

        $run = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('payroll_month', $anchor->copy()->startOfMonth())
            ->first();

        if ($run && $run->status !== 'Draft') {
            // Approved / Locked / Paid / Canceled: payroll is untouchable.
            $record = PayrollRecord::withoutGlobalScopes()
                ->where('payroll_run_id', $run->id)
                ->where('employee_id', $settlement->employee_id)
                ->first();
            return ['standalone', $run, $record];
        }

        try {
            $run = $this->payrollRuns->generate($anchor, [$settlement->employee_id], $companyId, $run?->is_transition ? $run : null);
        } catch (\Throwable) {
            // Generate aborts when the period is locked or overlaps an approved run.
            return ['standalone', null, null];
        }

        $record = PayrollRecord::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $settlement->employee_id)
            ->first();

        if (! $record) {
            // No payable salary record inside the run (e.g. no salary structure).
            return ['standalone', $run, null];
        }

        return ['draft_run', $run, $record];
    }
}
