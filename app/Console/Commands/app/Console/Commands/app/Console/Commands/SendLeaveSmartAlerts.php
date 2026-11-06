<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Notifications\LeaveSmartAlertNotification;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Smart leave alerts, one command per the app's existing notification pattern
 * (daily run, per-recipient dedupe, recipients filtered by permission).
 *
 * Alert kinds (all employee-specific, reason + date in the message):
 *  - leave_eligibility: the service wait (annual_wait_months, default 6)
 *    completes today and the full annual entitlement opens (no pro-rating).
 *  - leave_cycle_end_planning: the cycle ends within leave_cycle_end_alert_days
 *    (default 30) and the employee has actually taken less than 15 annual
 *    days this cycle (the 15-days-actual rule).
 *  - leave_six_consecutive: same window, and no single annual leave of 6+
 *    days was taken this cycle (the 6-consecutive-day rule).
 *  - leave_settlement_due: a known carried bucket's cycle end approaches 3
 *    years without settlement (90- and 30-day thresholds; settlement itself
 *    is manual — the alert never pays out or expires anything).
 *  - leave_history_uninitialized: pre-baseline history exists with no
 *    confirmed opening entry; authorized users get an actionable setup nudge
 *    instead of a fabricated balance.
 */
class SendLeaveSmartAlerts extends Command
{
    protected $signature = 'leaves:notify-smart';
    protected $description = 'Create employee-specific, actionable, deduplicated leave alerts for authorized users.';

    public function handle(): int
    {
        $sent = 0;
        $sent += $this->eligibilityAlerts();
        $sent += $this->cycleEndPlanningAlerts();
        $sent += $this->settlementAlerts();
        $sent += $this->uninitializedHistoryAlerts();
        $this->info("Leave smart alerts: {$sent} notification(s) created.");
        return self::SUCCESS;
    }

    private function eligibilityAlerts(): int
    {
        $sent = 0;
        foreach (Employee::withoutGlobalScopes()->whereNotNull('hire_date')->get() as $employee) {
            $waitMonths = max(0, (int) $this->policy('annual_wait_months', '6', $employee->company_id));
            $eligibleAt = Carbon::parse($employee->hire_date)->addMonths($waitMonths);
            if (!$eligibleAt->isToday()) continue;
            $sent += $this->send(
                $employee,
                'leave_eligibility',
                "{$employee->full_name} أكمل {$waitMonths} أشهر خدمة اليوم: أصبح مستحقًا لرصيده السنوي كاملًا اعتبارًا من {$eligibleAt->format('d/m/Y')}.",
                $eligibleAt,
                "leave_eligibility:{$employee->id}",
                'normal',
            );
        }
        return $sent;
    }

    private function cycleEndPlanningAlerts(): int
    {
        $window = max(1, (int) $this->policy('leave_cycle_end_alert_days', '30'));
        $required = (float) $this->policy('annual_min_actual_days', '15');
        $consecutive = (float) $this->policy('annual_min_consecutive_days', '6');
        $service = app(LeaveBalanceService::class);
        $sent = 0;

        foreach (Employee::withoutGlobalScopes()->whereNotNull('hire_date')->get() as $employee) {
            $cycle = $service->cycleFor($employee, today());
            $daysLeft = today()->diffInDays($cycle['end'], false);
            if ($daysLeft < 0 || $daysLeft > $window) continue;

            $leaves = Leave::with('leaveType')->where('employee_id', $employee->id)->effective()
                ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', $cycle['start'])->get()
                ->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance);
            $taken = (float) $leaves->sum('days');
            $maxSingle = (float) ($leaves->max('days') ?? 0);

            if ($taken < $required) {
                $sent += $this->send(
                    $employee,
                    'leave_cycle_end_planning',
                    "دورة {$employee->full_name} تنتهي في {$cycle['end']->format('d/m/Y')} (بعد {$daysLeft} يوم) وقد أخذ فعليًا {$this->number($taken)} من {$this->number($required)} يوم سنوية واجبة. رتّب الإجازات المتبقية قبل نهاية الدورة.",
                    $cycle['end'],
                    "leave_cycle_end_planning:{$employee->id}:{$cycle['start']->year}",
                    'high',
                );
            }
            if ($maxSingle < $consecutive) {
                $sent += $this->send(
                    $employee,
                    'leave_six_consecutive',
                    "دورة {$employee->full_name} تنتهي في {$cycle['end']->format('d/m/Y')} ولم يأخذ إجازة واحدة متصلة {$this->number($consecutive)} أيام على الأقل كما يشترط القانون. خطط لإجازة متصلة قبل {$cycle['end']->format('d/m/Y')}.",
                    $cycle['end'],
                    "leave_six_consecutive:{$employee->id}:{$cycle['start']->year}",
                    'high',
                );
            }
        }
        return $sent;
    }

    private function settlementAlerts(): int
    {
        $service = app(LeaveBalanceService::class);
        $sent = 0;
        foreach (Employee::withoutGlobalScopes()->whereNotNull('hire_date')->get() as $employee) {
            $cycle = $service->cycleFor($employee, today());
            $leaves = Leave::with('leaveType')->where('employee_id', $employee->id)->effective()
                ->whereDate('end_date', '>=', Carbon::parse($employee->hire_date))->get()
                ->filter(fn (Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance);
            $allocation = $service->allocate($employee, $leaves->values(), $cycle, 0.0, $service->openingEntriesFor($employee), $employee->company_id);
            foreach ($allocation['buckets'] as $bucket) {
                if ($bucket['source'] === 'current' || $bucket['remaining'] <= 0) continue;
                $deadline = $bucket['end']->copy()->addYears(3);
                $daysLeft = today()->diffInDays($deadline, false);
                if ($daysLeft < 0 || $daysLeft > 90) continue;
                $threshold = $daysLeft <= 30 ? 30 : 90;
                $sent += $this->send(
                    $employee,
                    'leave_settlement_due',
                    "رصيد مرحّل قديم لـ{$employee->full_name} (دورة {$bucket['year']}، متبقٍ {$this->number($bucket['remaining'])} يوم) يجب تسويته بالاستخدام أو تسوية مسجلة قبل {$deadline->format('d/m/Y')} (حد الثلاث سنوات).",
                    $deadline,
                    "leave_settlement_due:{$employee->id}:{$bucket['year']}:{$threshold}",
                    $threshold === 30 ? 'high' : 'normal',
                );
            }
        }
        return $sent;
    }

    private function uninitializedHistoryAlerts(): int
    {
        $service = app(LeaveBalanceService::class);
        $sent = 0;
        foreach (Employee::withoutGlobalScopes()->whereNotNull('hire_date')->get() as $employee) {
            $cycle = $service->cycleFor($employee, today());
            if (!$service->hasUninitializedHistory($employee, $cycle, $service->openingEntriesFor($employee), $employee->company_id)) continue;
            $sent += $this->send(
                $employee,
                'leave_history_uninitialized',
                "سجل إجازات {$employee->full_name} قبل بدء تتبع النظام غير مكتمل. سجّل رصيده الافتتاحي المؤكد (تسوية يدوية بتاريخها) حتى يظهر المرحّل الصحيح — الحساب لا يفترض أرصدة قديمة.",
                today(),
                "leave_history_uninitialized:{$employee->id}:{$cycle['start']->year}",
                'normal',
                route('leaves.opening.index'),
            );
        }
        return $sent;
    }

    private function send(Employee $employee, string $alertKind, string $message, Carbon $alertDate, string $dedupeKey, string $priority, ?string $url = null): int
    {
        $recipients = User::withoutGlobalScopes()->with('role.permissions')->where('is_active', true)
            ->where('company_id', $employee->company_id)->get()
            ->filter(fn (User $user) => $user->hasPermission('leaves.view'));
        $sent = 0;
        foreach ($recipients as $user) {
            $exists = $user->notifications()->where('type', LeaveSmartAlertNotification::class)
                ->where('data->dedupe_key', $dedupeKey)->exists();
            if (!$exists) {
                $user->notify(new LeaveSmartAlertNotification($employee, $alertKind, $message, $alertDate, $dedupeKey, $priority, $url));
                $sent++;
            }
        }
        return $sent;
    }

    private function policy(string $key, string $default, ?int $companyId = null): string
    {
        if ($companyId) {
            $value = DB::table('leave_policy_settings')->where('setting_key', $key)->where('company_id', $companyId)->value('setting_value');
            if ($value !== null) return (string) $value;
        }
        return (string) (DB::table('leave_policy_settings')->where('setting_key', $key)->whereNull('company_id')->value('setting_value') ?? $default);
    }

    private function number(float|int|string $value): string
    {
        $value = (float) $value;
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
