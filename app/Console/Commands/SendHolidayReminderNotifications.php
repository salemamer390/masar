<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\HolidayReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendHolidayReminderNotifications extends Command
{
    protected $signature = 'holidays:notify-upcoming';
    protected $description = 'Notify authorized users seven and three days before an official holiday.';

    public function handle(): int
    {
        $recipients = User::with('role.permissions')->where('is_active', true)->get()
            ->filter(fn (User $user) => $user->hasPermission('payroll.settings'));
        $sent = 0;

        foreach ([7, 3] as $days) {
            $holidays = DB::table('hr_holidays')->whereDate('holiday_date', today()->addDays($days))->get();
            foreach ($holidays as $holiday) {
                foreach ($recipients as $recipient) {
                    $exists = $recipient->notifications()
                        ->where('type', HolidayReminderNotification::class)
                        ->where('data->holiday_id', $holiday->id)
                        ->where('data->days_remaining', $days)
                        ->exists();
                    if (!$exists) {
                        $recipient->notify(new HolidayReminderNotification($holiday, $days));
                        $sent++;
                    }
                }
            }
        }

        $this->info("Holiday reminders sent: {$sent}.");
        return self::SUCCESS;
    }
}
