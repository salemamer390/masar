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
            $this->info['overdue'] => "خطر: تأخر فصل الموظف {$name} من التأمينات ".abs($left).' يوم عن المهلة. قدّم استمارة 6 واضغط «تم فصله» الآن.',
            $left <= 2 => "خطر: باقي {$left} يوم فقط لفصل الموظف {$name} من التأمينات (استمارة 6). اضغط «تم فصله» بعد التقديم.",
            default => "تنبيه: الموظف {$name} ترك العمل ويجب فصله من التأمينات خلال أسبوع (استمارة 6). باقي {$left} أيام.",
        };

        return [
            'kind' => 'insurance_deregistration',
            'severity' => $this->info['level'],
            'employee_id' => $this->employee->id,
            'employee_code' => $this->employee->employee_code,
            'termination_date' => optional($this->employee->termination_date)->toDateString(),
            'reminder_key' => $this->reminderKey,
            'message' => $message,
            'url' => route('insurance.index', ['search' => $this->employee->employee_code]),
        ];
    }
}
