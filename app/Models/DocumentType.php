<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['name', 'category', 'is_required', 'is_active'];
    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
