<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovementType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(MovementRecord::class);
    }
}
