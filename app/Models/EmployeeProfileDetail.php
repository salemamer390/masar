<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class EmployeeProfileDetail extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'nationality', 'personal_email', 'work_pattern', 'weekly_work_days', 'daily_work_hours',
        'working_hours_text', 'contract_type', 'probation_period', 'annual_leave_days',
        'contract_attachment_status', 'additional_conditions', 'currency', 'national_id_issue_date',
        'national_id_expiry_date', 'national_id_place', 'military_status',
    ];

    protected $casts = [
        'daily_work_hours' => 'decimal:2', 'annual_leave_days' => 'decimal:2',
        'national_id_issue_date' => 'date', 'national_id_expiry_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
