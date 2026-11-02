<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A finished payroll period. It is created "pending" the moment the period ends (its
 * boundaries are fixed from then on) and stays open for corrections for a few days.
 * When it closes, the attendance is saved in `rows`; a closed period is never edited
 * or deleted, and no attendance row inside it can be changed.
 */
class AttendancePeriodArchive extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';
    public const CLOSED = 'closed';

    protected $fillable = ['company_id', 'period_start', 'period_end', 'status', 'closes_on', 'rows', 'record_count', 'employee_count', 'settings', 'captured_at'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'closes_on' => 'date',
        'rows' => 'array',
        'settings' => 'array',
        'captured_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Boundaries never change; only pending -> closed (once) is allowed.
        static::updating(function (self $archive) {
            if ($archive->getOriginal('status') !== self::PENDING
                || $archive->isDirty(['company_id', 'period_start', 'period_end', 'closes_on'])) {
                throw new \LogicException('Closed attendance periods are read-only.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Attendance periods cannot be deleted.'));
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
