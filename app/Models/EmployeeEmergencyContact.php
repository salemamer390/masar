<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class EmployeeEmergencyContact extends Model
{
    use BelongsToCompany;
    protected $fillable = ['employee_id', 'name', 'phone', 'relationship', 'is_primary'];
    protected $casts = ['is_primary' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
