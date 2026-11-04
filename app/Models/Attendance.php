<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class Attendance extends Model
{
    use BelongsToCompany;
    use \App\Models\Concerns\ImmediatelyEffective;

    /**
     * No write path (sync, import, manual, recalculation, jobs) can change a closed period.
     * The check and the write run in one transaction that holds a shared lock on the company
     * row; closing a period takes the exclusive lock on the same row. So a writer either
     * commits before the snapshot is read (and is in it) or waits and is then refused.
     */
    public function save(array $options = [])
    {
        return $this->guardedWrite(fn () => parent::save($options));
    }

    public function delete()
    {
        return $this->guardedWrite(fn () => parent::delete());
    }

    private function guardedWrite(\Closure $write)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($write) {
            $archive = app(\App\Services\AttendancePeriodArchiveService::class);
            $companyId = $this->company_id ?: \App\Support\CompanyContext::id();
            $companyId = $companyId ? (int) $companyId : null;
            if ($companyId) $archive->lockCompanyForWrite($companyId);
            foreach (array_unique(array_filter([$this->getOriginal('attendance_date'), $this->attendance_date], fn ($d) => $d)) as $date) {
                $archive->ensureDateOpen($date, $companyId, true);
            }

            return $write();
        });
    }

    protected $table = 'attendance';

    protected $fillable = [
        'employee_id',
        'attendance_date',
        'check_in',
        'check_out',
        'status',
        'source',
        'approval_status',
        'approved_by',
        'approved_at',
        'late_minutes',
        'overtime_minutes',
        'early_leave_minutes',
        'worked_hours',
        'notes',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'late_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'worked_hours' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getFormattedLateAttribute(): string
    {
        if (!$this->late_minutes) {
            return '00:00';
        }
        $h = floor($this->late_minutes / 60);
        $m = $this->late_minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'حاضر', 'Present' => 'success',
            'متأخر', 'Late' => 'warning',
            'غياب', 'Absent' => 'danger',
            'إجازة', 'On Leave' => 'info',
            default => 'secondary',
        };
    }
}
