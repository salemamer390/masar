<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A leave overlaps a day that has an attendance record. The notice stays
 * actionable (confirm button) and is repeated until someone confirms it.
 * It never changes attendance, leave or payroll data.
 */
class LeaveAttendanceConflictNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Employee $employee,
        private readonly int $leaveId,
        private readonly Carbon $attendanceDate,
        private readonly string $dedupeKey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'leave_attendance_conflict',
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->full_name,
            'leave_id' => $this->leaveId,
            'alert_date' => $this->attendanceDate->toDateString(),
            'dedupe_key' => $this->dedupeKey,
            'requires_confirmation' => true,
            'confirmed_at' => null,
            'message' => __('تعارض: الموظف :name لديه إجازة في يوم :date وعليه حضور مسجل. راجع اليوم ثم اضغط تأكيد.', [
                'name' => $this->employee->full_name,
                'date' => $this->attendanceDate->format('d/m/Y'),
            ]),
            'url' => route('attendance.index', [
                'employee_id' => $this->employee->id,
                'from_date' => $this->attendanceDate->toDateString(),
                'to_date' => $this->attendanceDate->toDateString(),
                'highlight' => $this->attendanceDate->toDateString(),
            ]),
        ];
    }
}
