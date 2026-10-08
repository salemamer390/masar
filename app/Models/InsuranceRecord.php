<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'insurance_number',
        'job_code',
        'insurance_start_date',
        'insurance_end_date',
        'insured_salary',
        'status',
        'notes',
    ];

    protected $casts = [
        'insurance_start_date' => 'date',
        'insurance_end_date' => 'date',
        'insured_salary' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'مسجل', 'Active' => 'success',
            'قيد التسجيل', 'Pending' => 'warning',
            default => 'danger',
        };
    }
}
