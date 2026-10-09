<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class HolidayReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly object $holiday, private readonly int $daysRemaining) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $date = Carbon::parse($this->holiday->holiday_date);

        return [
            'kind' => 'holiday_reminder',
            'holiday_id' => $this->holiday->id,
            'holiday_name' => $this->holiday->name,
            'holiday_date' => $date->toDateString(),
            'days_remaining' => $this->daysRemaining,
            'message' => "تذكير: عطلة {$this->holiday->name} يوم {$date->format('d/m/Y')}، متبقي {$this->daysRemaining} أيام.",
            'url' => route('payroll.settings.index'),
        ];
    }
}
