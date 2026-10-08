<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class ContractExpiryNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Employee $employee)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $endDate = Carbon::parse($this->employee->contract_end_date);
        return [
            'kind' => 'contract_expiry',
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->full_name,
            'contract_end_date' => $endDate->toDateString(),
            'days_remaining' => max(0, now()->startOfDay()->diffInDays($endDate, false)),
            'message' => "عقد {$this->employee->full_name} ينتهي في {$endDate->format('d/m/Y')}.",
            'url' => route('employees.show', $this->employee),
        ];
    }
}
