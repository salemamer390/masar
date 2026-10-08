<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenaltyType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
        'default_deduction_days',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_deduction_days' => 'decimal:2',
    ];

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }
}
