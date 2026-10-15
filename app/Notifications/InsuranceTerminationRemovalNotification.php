<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Notifications\Notification;

class InsuranceTerminationRemovalNotification extends Notification
{
    public function __construct(private readonly Employee $employee) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $message = "تنبيه تأمينات: الموظف {$this->employee->full_name} انتهت خدمته وما زال مسجلًا بالتأمينات. يرجى تقديم استمارة 6 وتسجيل تاريخ إنهاء التأمين.";

        return [
            'kind' => 'insurance_termination_removal',
            'employee_id' => $this->employee->id,
            'employee_code' => $this->employee->employee_code,
            'termination_date' => optional($this->employee->termination_date)->toDateString(),
            'reminder_date' => today()->toDateString(),
            'message' => $message,
            'url' => route('insurance.index', ['search' => $this->employee->employee_code]),
        ];
    }
}
