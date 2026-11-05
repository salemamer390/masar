<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Immutable who/when/why audit for every fix or dismissal of a rejected row. */
class EmployeeImportRowRevision extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
    protected $casts = ['before_data' => 'encrypted:array', 'after_data' => 'encrypted:array', 'created_at' => 'datetime'];
    protected $hidden = ['before_data', 'after_data'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Import review audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Import review audit records cannot be deleted.'));
    }

    public function row()
    {
        return $this->belongsTo(EmployeeImportRow::class, 'import_row_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
