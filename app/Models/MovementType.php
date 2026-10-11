<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovementType extends Model
{
    use BelongsToCompanyOrGlobal;
    public $timestamps = false;

    protected $fillable = [
        'name',
        'company_id',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(MovementRecord::class);
    }
}
