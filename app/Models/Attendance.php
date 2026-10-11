<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class Attendance extends Model
{
    use BelongsToCompany;
    protected $table = 'attendance';

    protected $fillable = [
        'employee_id',
        'attendance_date',
        'check_in',
        'check_out',
        'status',
        'source',
        'approval_status',
        'approved_by',
        'approved_at',
        'late_minutes',
        'overtime_minutes',
        'early_leave_minutes',
        'worked_hours',
        'notes',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'late_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'worked_hours' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getFormattedLateAttribute(): string
    {
        if (!$this->late_minutes) {
            return '00:00';
        }
        $h = floor($this->late_minutes / 60);
        $m = $this->late_minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'حاضر', 'Present' => 'success',
            'متأخر', 'Late' => 'warning',
            'غياب', 'Absent' => 'danger',
            'إجازة', 'On Leave' => 'info',
            default => 'secondary',
        };
    }
}
