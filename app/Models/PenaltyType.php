<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenaltyType extends Model
{
    use BelongsToCompanyOrGlobal;
    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
        'default_deduction_days',
        'company_id',
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
