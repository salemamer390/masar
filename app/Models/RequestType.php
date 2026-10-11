<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RequestType extends Model
{
    use BelongsToCompanyOrGlobal;
    protected $fillable = [
        'code',
        'display_name',
        'source_key',
        'icon',
        'icon_color',
        'icon_background',
        'metadata',
        'is_active',
        'sort_order',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
