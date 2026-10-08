<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'default_days',
        'deducts_annual_balance',
        'is_paid',
        'is_active',
    ];

    protected $casts = [
        'default_days' => 'decimal:2',
        'is_active' => 'boolean',
        'deducts_annual_balance' => 'boolean',
        'is_paid' => 'boolean',
    ];

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }
}
