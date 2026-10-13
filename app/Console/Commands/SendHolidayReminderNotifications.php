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
        $recipients = collect();
        $sent = 0;

        foreach ([7, 3] as $days) {
            $holidays = DB::table('hr_holidays')->whereDate('holiday_date', today()->addDays($days))->get();
            foreach ($holidays as $holiday) {
                $recipients = User::with('role.permissions')->where('is_active', true)->where('company_id', $holiday->company_id)->get()
                    ->filter(fn (User $user) => $user->hasPermission('payroll.settings'));
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
