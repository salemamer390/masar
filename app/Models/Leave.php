<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class Leave extends Model
{
    use BelongsToCompany;
    use \App\Models\Concerns\ImmediatelyEffective;
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days',
        'reason',
        'status',
        'manager_approved_by',
        'manager_approved_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'notes',
        'is_early_grant',
        'early_grant_reason',
        'early_grant_approved_by',
        'early_grant_approved_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => 'decimal:2',
        'approved_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'is_early_grant' => 'boolean',
        'early_grant_approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getStatusBadgeClassAttribute(): string
    {
        if (!in_array($this->status, self::inactiveStatuses(), true)) return 'success';
        return match ($this->status) {
            'معتمدة', 'Approved' => 'success',
            'قيد الاعتماد', 'معلقة', 'Pending' => 'warning',
            'مرفوضة', 'Rejected' => 'danger',
            default => 'secondary',
        };
    }
}
