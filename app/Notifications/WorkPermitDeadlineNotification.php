<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WorkPermitDeadlineNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Employee $employee) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        $deadline = Carbon::parse($this->employee->hire_date ?? $this->employee->created_at)->addDays(30)->startOfDay();

        return [
            'kind' => 'work_permit_deadline',
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->full_name,
            'deadline' => $deadline->toDateString(),
            'days_allowed' => 30,
            'message' => "يرجى استكمال استخراج كعب العمل للموظف {$this->employee->full_name} خلال 30 يومًا من تاريخ بداية العقد، بحد أقصى {$deadline->format('d/m/Y')}.",
            'url' => route('employees.show', $this->employee),
        ];
    }
}
