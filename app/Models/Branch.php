<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToCompanyOrGlobal;
    protected $fillable = [
        'name',
        'address',
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
