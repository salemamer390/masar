<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class MovementRecord extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'employee_id',
        'movement_type_id',
        'movement_date',
        'old_department_id',
        'new_department_id',
        'old_job_id',
        'new_job_id',
        'old_salary',
        'new_salary',
        'reason',
        'old_values',
        'new_values',
        'attachment_path',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'old_salary' => 'decimal:2',
        'new_salary' => 'decimal:2',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function movementType(): BelongsTo
    {
        return $this->belongsTo(MovementType::class);
    }

    public function oldDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'old_department_id');
    }

    public function newDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'new_department_id');
    }

    public function oldJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'old_job_id');
    }

    public function newJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'new_job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
