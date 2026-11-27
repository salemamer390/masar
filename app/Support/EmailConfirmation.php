<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\Auth\EmailConfirmationReminderNotification;

/**
 * Rules for confirming the email of a newly registered company owner.
 * The account keeps working for GRACE_DAYS, then is locked (never deleted)
 * until the link is opened.
 */
class EmailConfirmation
{
    public const GRACE_DAYS = 7;

    /** Reminder emails go out this many days after registration. */
    public const REMINDER_DAYS = [3, 6];

    /** Sends the reminders that are due. Safe to call often; each reminder goes out once. */
    public static function sendDueReminders(int $limit = 25): int
    {
        $sent = 0;
        $users = User::query()
            ->whereNull('email_verified_at')
            ->whereNotNull('email_confirm_deadline_at')
            ->where('email_confirm_deadline_at', '>', now())
            ->where('email_confirm_reminders', '<', count(self::REMINDER_DAYS))
            ->limit($limit)->get();

        foreach ($users as $user) {
            $elapsed = now()->floatDiffInDays($user->email_confirm_deadline_at->copy()->subDays(self::GRACE_DAYS), false) * -1;
            // Count every reminder that is due, so a late visitor gets one email, not a burst.
            $dueCount = count(array_filter(self::REMINDER_DAYS, fn ($day) => $elapsed >= $day));
            if ($dueCount <= $user->email_confirm_reminders) {
                continue;
            }
            $user->notifySafely(new EmailConfirmationReminderNotification($user->emailConfirmationDaysLeft()));
            $user->forceFill(['email_confirm_reminders' => $dueCount])->save();
            $sent++;
        }

        return $sent;
    }
}
