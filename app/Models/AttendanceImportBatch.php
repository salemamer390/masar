<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
/** Private lineage: no download/public disk. Retained until an explicit company data-retention operation. */
class AttendanceImportBatch extends Model {
    use BelongsToCompany;
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Original import and audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Original import and audit records cannot be deleted.'));
    }
    protected $guarded = ['id'];
    protected $casts = ['original_data' => 'encrypted:array'];
    protected $hidden = ['original_data'];
}
