<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A rejected workbook row kept (encrypted) for in-app review, fix and audit. */
class EmployeeImportRow extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'payload' => 'encrypted:array',
        'errors' => 'array',
        'resolved_at' => 'datetime',
    ];
    protected $hidden = ['payload'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Use the audited dismiss path.'));
        static::updating(function ($row) {
            if ($row->isDirty(['batch_id', 'company_id', 'sheet', 'line', 'kind'])) {
                throw new \LogicException('Original import lineage cannot change.');
            }
        });
    }

    public function batch()
    {
        return $this->belongsTo(EmployeeImportBatch::class, 'batch_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function revisions()
    {
        return $this->hasMany(EmployeeImportRowRevision::class, 'import_row_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
