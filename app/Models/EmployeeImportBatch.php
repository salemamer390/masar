<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Metadata-only lineage for a personnel import. Immutable audit record. */
class EmployeeImportBatch extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = ['sheets' => 'array', 'distributed' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Import batch lineage is immutable.'));
        static::deleting(fn () => throw new \LogicException('Import batch lineage cannot be deleted.'));
    }

    public function rows()
    {
        return $this->hasMany(EmployeeImportRow::class, 'batch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
