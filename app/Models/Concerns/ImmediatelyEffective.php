<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Operational records have no approval queue. Cancellation stays explicit. */
trait ImmediatelyEffective
{
    public static function bootImmediatelyEffective(): void
    {
        static::saving(function ($record): void {
            $field = $record instanceof \App\Models\Attendance ? 'approval_status' : 'status';
            if (!$record->exists || $record->isDirty($field)) {
                if (!in_array($record->{$field}, self::inactiveStatuses(), true)) {
                    $record->{$field} = 'Approved';
                }
            }
        });
    }

    public static function inactiveStatuses(): array
    {
        return ['Rejected', 'Cancelled', 'Canceled', 'مرفوض', 'مرفوضة', 'ملغي', 'ملغى', 'ملغاة'];
    }

    public function scopeEffective(Builder $query): Builder
    {
        $field = $this instanceof \App\Models\Attendance ? 'approval_status' : 'status';
        return $query->where(fn (Builder $q) => $q->whereNull($field)->orWhereNotIn($field, self::inactiveStatuses()));
    }
}
