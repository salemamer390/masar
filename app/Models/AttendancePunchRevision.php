<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
class AttendancePunchRevision extends Model {
    use BelongsToCompany;
    public $timestamps = false;
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Original import and audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Original import and audit records cannot be deleted.'));
    }
    protected $guarded = ['id'];
    protected $casts = ['before_data'=>'encrypted:array','after_data'=>'encrypted:array','created_at'=>'datetime'];
    protected $hidden = ['before_data','after_data'];
    public function user() { return $this->belongsTo(User::class); }
}
