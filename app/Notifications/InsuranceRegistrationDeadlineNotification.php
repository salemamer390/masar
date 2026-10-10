<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;

class InsuranceRegistrationDeadlineNotification extends Notification
{
    public function __construct(private readonly Employee $employee, private readonly Carbon $deadline, private readonly int $daysRemaining) {}
    public function via(object $notifiable): array { return ['database']; }
    public function toDatabase(object $notifiable): array
    {
        $message = $this->daysRemaining >= 0
            ? "تذكير تأمينات: يتبقى {$this->daysRemaining} أيام لتسجيل الموظف {$this->employee->full_name} بالتأمينات، وآخر موعد {$this->deadline->format('d/m/Y')}."
            : "تنبيه تأمينات: تأخر تسجيل الموظف {$this->employee->full_name} بالتأمينات عن الموعد المحدد {$this->deadline->format('d/m/Y')}.";
        return ['kind' => 'insurance_registration_deadline', 'employee_id' => $this->employee->id, 'deadline' => $this->deadline->toDateString(), 'days_remaining' => $this->daysRemaining, 'message' => $message, 'url' => route('insurance.index')];
    }
}
