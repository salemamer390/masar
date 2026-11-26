<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One overtime day no request covers: the message names the employee, the
 * day and the uncovered minutes, the dedupe_key drives the repeat schedule,
 * and the severity flips the centre row between warning and danger.
 */
class OvertimeGapReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly array $gap,
        private readonly Carbon $periodEnd,
        private readonly string $dedupeKey,
        private readonly string $severity = 'warning',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $employee = $this->gap['employee'];
        $minutes = (int) $this->gap['day_minutes'] + (int) $this->gap['night_minutes'];
        $date = Carbon::parse($this->gap['date']);

        return [
            'kind' => 'overtime_gap',
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'date' => $date->toDateString(),
            'minutes' => $minutes,
            'severity' => $this->severity,
            'dedupe_key' => $this->dedupeKey,
            'message' => __('حضور إضافي بدون طلب: :name يوم :date (:minutes دقيقة).', [
                'name' => $employee->full_name,
                'date' => $date->format('d/m/Y'),
                'minutes' => format_number($minutes),
            ]),
            'url' => route('jobs.overtime-review', ['month' => $date->format('Y-m'), 'search' => $employee->employee_code]),
        ];
    }
}
