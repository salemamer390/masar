<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class Penalty extends Model
{
    use BelongsToCompany;
    use \App\Models\Concerns\ImmediatelyEffective;
    protected $fillable = [
        'employee_id',
        'penalty_type_id',
        'penalty_date',
        'title',
        'reason',
        'action',
        'amount',
        'deduction_days',
        'status',
        'issued_by',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'penalty_date' => 'date',
        'amount' => 'decimal:2',
        'deduction_days' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function penaltyType(): BelongsTo
    {
        return $this->belongsTo(PenaltyType::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function getStatusBadgeClassAttribute(): string
    {
        if (!in_array($this->status, self::inactiveStatuses(), true)) return 'success';
        return match ($this->status) {
            'مغلقة', 'Closed', 'معتمد', 'Approved' => 'success',
            'قيد المتابعة', 'Open', 'Pending' => 'warning',
            default => 'danger',
        };
    }
}
