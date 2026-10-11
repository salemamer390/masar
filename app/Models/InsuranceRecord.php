<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class InsuranceRecord extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'employee_id',
        'insurance_number',
        'job_code',
        'insurance_office',
        'insurance_category',
        'insurance_start_date',
        'registration_deadline',
        'insurance_end_date',
        'insured_salary',
        'status',
        'notes',
    ];

    protected $casts = [
        'insurance_start_date' => 'date',
        'registration_deadline' => 'date',
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
            'registered' => 'success',
            'unregistered' => 'danger',
            'مسجل', 'Active' => 'success',
            'قيد التسجيل', 'Pending' => 'warning',
            default => 'danger',
        };
    }
}
