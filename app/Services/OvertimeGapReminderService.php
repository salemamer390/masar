<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\OvertimeGapReminderNotification;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Repeating reminders for overtime days no request covers. One notification
 * thread per employee+day, escalating: the first repeat after a week, the
 * next after two weeks, then every five days, and daily from the day before
 * the period ends. The reminder turns danger-styled in the period's last
 * days. It stops when a request covers the day (the gap disappears) or when
 * a user dismisses it; dismissing only silences the reminder and never
 * touches attendance, requests or payroll.
 */
class OvertimeGapReminderService
{
    /** Repeat intervals in days: after the 1st notice, after the 2nd, then every time. */
    public const REPEAT_AFTER_DAYS = [7, 14, 5];

    /** The reminder switches to the danger style this many days before the period ends. */
    public const DANGER_WITHIN_DAYS = 4;

    /** From this many days before the period ends, reminders repeat daily. */
    public const DAILY_WITHIN_DAYS = 1;

    public static function key(int $employeeId, string $date): string
    {
        return "overtime_gap:{$employeeId}:{$date}";
    }

    public static function severity(Carbon $periodEnd, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return $now->copy()->startOfDay()->gte($periodEnd->copy()->subDays(self::DANGER_WITHIN_DAYS)->startOfDay())
            ? 'danger'
            : 'warning';
    }

    /** When the next reminder is due, given how many were already sent. */
    public static function nextDueAt(int $alreadySent, Carbon $latestSentAt, Carbon $periodEnd, ?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now();
        $interval = match (true) {
            $alreadySent <= 1 => self::REPEAT_AFTER_DAYS[0],
            $alreadySent === 2 => self::REPEAT_AFTER_DAYS[1],
            default => self::REPEAT_AFTER_DAYS[2],
        };
        // The final stretch repeats daily (the day before the end, the end
        // day itself, and each day after while the gap stays open).
        if ($now->copy()->startOfDay()->gte($periodEnd->copy()->subDays(self::DAILY_WITHIN_DAYS)->startOfDay())) {
            $interval = min($interval, 1);
        }

        return $latestSentAt->copy()->addDays($interval);
    }

    /** Overtime gaps for the company's current period, computed in its own context. */
    public function gaps(int $companyId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return CompanyContext::run($companyId, fn () => app(OvertimeGapService::class)->collect(
            Employee::select(['id', 'employee_code', 'full_name', 'shift_start', 'shift_end']),
            $now
        ));
    }

    public function periodEnd(int $companyId, ?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now();

        return CompanyContext::run($companyId, fn () => app(PayrollRunService::class)->periodFor($now)['end']);
    }

    /** Send new reminders and due repeats. Returns the number sent. */
    public function notify(?int $companyId = null, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $companyIds = $companyId !== null
            ? [$companyId]
            : Company::withoutGlobalScopes()->pluck('id')->all();

        $sent = 0;
        foreach ($companyIds as $cid) {
            $cid = (int) $cid;
            $periodEnd = $this->periodEnd($cid, $now);
            $recipients = $this->recipients($cid);
            if ($recipients->isEmpty()) continue;
            foreach ($this->gaps($cid, $now) as $gap) {
                $key = self::key((int) $gap['employee']->id, $gap['date']);
                if ($this->isDismissed($cid, $key)) continue;
                foreach ($recipients as $user) {
                    $items = $this->forKey($user, $key);
                    $count = (clone $items)->count();
                    $latest = (clone $items)->latest()->first();
                    if ($latest && $now->lt(self::nextDueAt($count, $latest->created_at, $periodEnd, $now))) continue;
                    // The newest reminder supersedes the older unread ones.
                    if ($latest && !$latest->read_at) $latest->markAsRead();
                    $user->notify(new OvertimeGapReminderNotification($gap, $periodEnd, $key, self::severity($periodEnd, $now)));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** Dismiss a gap's reminders for the whole company. Acknowledgement only. */
    public function dismiss(User $actor, DatabaseNotification $notification): void
    {
        $key = (string) ($notification->data['dedupe_key'] ?? '');
        abort_unless($key !== '' && ($notification->data['kind'] ?? '') === 'overtime_gap', 404);
        $stamp = ['dismissed_at' => now()->toIso8601String(), 'dismissed_by' => $actor->id, 'dismissed_by_name' => $actor->name];
        foreach ($this->recipients((int) $actor->company_id) as $user) {
            foreach ($this->forKey($user, $key)->get() as $item) {
                $item->forceFill(['data' => array_merge($item->data, $stamp), 'read_at' => $item->read_at ?? now()])->save();
            }
        }
    }

    private function isDismissed(int $companyId, string $key): bool
    {
        foreach ($this->recipients($companyId) as $user) {
            if ($this->forKey($user, $key)->whereNotNull('data->dismissed_at')->exists()) return true;
        }
        return false;
    }

    private function forKey(User $user, string $key)
    {
        return $user->notifications()->where('type', OvertimeGapReminderNotification::class)->where('data->dedupe_key', $key);
    }

    private function recipients(int $companyId)
    {
        return User::withoutGlobalScopes()->with('role.permissions')->where('is_active', true)
            ->where('company_id', $companyId)->get()->filter(fn (User $u) => $u->hasPermission('attendance.view'));
    }
}
