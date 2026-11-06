<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One smart leave alert for one employee: the message always carries the
 * reason and the relevant date, a stable dedupe_key prevents repeats, and a
 * priority lets the centre sort what needs action first.
 */
class LeaveSmartAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Employee $employee,
        private readonly string $alertKind,
        private readonly string $message,
        private readonly Carbon $alertDate,
        private readonly string $dedupeKey,
        private readonly string $priority = 'normal',
        private readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'leave',
            'alert_kind' => $this->alertKind,
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->full_name,
            'alert_date' => $this->alertDate->toDateString(),
            'priority' => $this->priority,
            'dedupe_key' => $this->dedupeKey,
            'message' => $this->message,
            'url' => $this->url ?? route('employees.show', $this->employee),
        ];
    }
}
