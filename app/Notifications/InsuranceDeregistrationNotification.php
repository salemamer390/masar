<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Notifications\Notification;

class InsuranceDeregistrationNotification extends Notification
{
    /** @param array{days:int,left:int,level:string,overdue:bool} $info */
    public function __construct(private readonly Employee $employee, private readonly array $info, private readonly string $reminderKey) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = $this->employee->full_name;
        $left = $this->info['left'];
        $message = match (true) {
            $this->info['overdue'] => "خطر: فصل {$name} متأخر ".abs($left).' يوم (س6)',
            $left <= 2 => "خطر: فصل {$name} من التأمينات باقي {$left} يوم",
            default => "تنبيه: فصل {$name} من التأمينات باقي {$left} أيام",
        };

        return [
            'kind' => 'insurance_deregistration',
            'severity' => $this->info['level'],
            'employee_id' => $this->employee->id,
            'record_id' => $this->employee->insuranceRecord?->id,
            'employee_code' => $this->employee->employee_code,
            'termination_date' => optional($this->employee->termination_date)->toDateString(),
            'reminder_key' => $this->reminderKey,
            'message' => $message,
            'url' => route('insurance.index', ['search' => $this->employee->employee_code]),
        ];
    }
}
