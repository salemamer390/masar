<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveOpeningEntry extends Model
{
    protected $fillable = ['company_id', 'employee_id', 'cycle_year', 'amount', 'is_confirmed_zero', 'reason', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'is_confirmed_zero' => 'boolean'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
