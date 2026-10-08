<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrOperationalSettingsService
{
    public function value(string $key, string $default = ''): string
    {
        return (string) (DB::table('hr_operational_settings')->where('setting_key', $key)->value('setting_value') ?? $default);
    }
    public function start(): string { return $this->value('default_shift_start', '09:00'); }
    public function end(): string { return $this->value('default_shift_end', '17:00'); }
    public function grace(): int { return (int) $this->value('grace_minutes', '15'); }
    public function absenceCutoff(): string { return $this->value('absence_cutoff_time', $this->end()); }
    public function weeklyRestPattern(): string
    {
        return str_pad($this->value('weekly_rest_pattern', '0000100'), 7, '0');
    }

    public function isWeeklyRest(Carbon $date): bool
    {
        $pattern = $this->weeklyRestPattern();
        return ($pattern[$date->dayOfWeekIso - 1] ?? '0') === '1';
    }

    public function excludeRestAndHolidaysFromLeave(): bool
    {
        return filter_var($this->value('leave_exclude_rest_holidays', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function isHoliday(Carbon $date): bool
    {
        if (!Schema::hasTable('hr_holidays')) {
            return false;
        }

        return DB::table('hr_holidays')->whereDate('holiday_date', $date->toDateString())->exists();
    }

}
