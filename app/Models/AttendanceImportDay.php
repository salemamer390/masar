<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
class AttendanceImportDay extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected $casts = ['original_punches' => 'encrypted:array', 'current_punches' => 'encrypted:array', 'work_date' => 'date'];
    protected $hidden = ['original_punches', 'current_punches'];
    protected static function booted(): void {
        static::deleting(fn () => throw new \LogicException('Use the audited exclusion path.'));
        static::updating(function ($day) { if ($day->isDirty(['original_punches','employee_id','work_date','batch_id','company_id'])) throw new \LogicException('Original lineage cannot change.'); });
        static::saving(function ($day) {
            $archive=app(\App\Services\AttendancePeriodArchiveService::class);
            $archive->lockCompanyForWrite((int)($day->company_id ?: auth()->user()?->company_id));
            foreach (array_filter([$day->getOriginal('work_date'),$day->work_date]) as $date) {
                app(\App\Services\PayrollPeriodGuard::class)->ensureOpen($date); $archive->ensureDateOpen($date);
            }
        });
    }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function batch() { return $this->belongsTo(AttendanceImportBatch::class, 'batch_id'); }
    public function revisions() { return $this->hasMany(AttendancePunchRevision::class, 'import_day_id'); }
}
