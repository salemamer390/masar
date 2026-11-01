<?php

namespace App\Services;

use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company operational settings (shift times, rest days, night window, holidays).
 * Results are cached per instance so payroll runs over hundreds of employees
 * do not query the database for every single day.
 */
class HrOperationalSettingsService
{
    private array $valueCache = [];

    private array $holidayCache = [];

    public function flush(): void
    {
        $this->valueCache = [];
        $this->holidayCache = [];
    }

    private function companyKey(): string
    {
        return (string) (CompanyContext::id() ?? 'global');
    }

    public function value(string $key, string $default = ''): string
    {
        $cacheKey = $this->companyKey() . '|' . $key;
        if (array_key_exists($cacheKey, $this->valueCache)) {
            return $this->valueCache[$cacheKey] ?? $default;
        }

        $query = DB::table('hr_operational_settings')->where('setting_key', $key);
        $companyId = CompanyContext::id();
        if ($companyId) $query->where('company_id', $companyId);
        $value = $query->value('setting_value');
        $this->valueCache[$cacheKey] = $value === null ? null : (string) $value;

        return (string) ($value ?? $default);
    }

    public function start(): string { return $this->value('default_shift_start', '09:00'); }
    public function end(): string { return $this->value('default_shift_end', '17:00'); }

    /** First day number of the recurring payroll/attendance period. */
    public function payrollPeriodStartDay(): int
    {
        return max(1, min(31, (int) $this->value('payroll_period_start_day', '1')));
    }

    /** Last day number of the recurring payroll/attendance period. */
    public function payrollPeriodEndDay(): int
    {
        return max(1, min(31, (int) $this->value('payroll_period_end_day', '30')));
    }

    /**
     * Resolve the concrete period represented by a payroll month.
     * If the start day is after the end day, the period crosses two calendar months
     * (for example 26/25 means 26th of the previous month through 25th of this month).
     * The supplied month is the month in which the period ends.
     *
     * @return array{start: Carbon,end: Carbon}
     */
    public function payrollPeriod(Carbon $month): array
    {
        $anchor = $month->copy()->startOfMonth();
        $startDay = $this->payrollPeriodStartDay();
        $endDay = $this->payrollPeriodEndDay();
        $endMonth = $anchor->copy();
        $startMonth = $startDay > $endDay ? $anchor->copy()->subMonth() : $anchor->copy();

        $start = $startMonth->copy()->day(min($startDay, $startMonth->daysInMonth))->startOfDay();
        $end = $endMonth->copy()->day(min($endDay, $endMonth->daysInMonth))->endOfDay();

        return ['start' => $start, 'end' => $end];
    }

    /** Return the payroll anchor month that owns a concrete calendar date. */
    public function payrollMonthForDate(Carbon $date): Carbon
    {
        $date = $date->copy()->startOfDay();
        $period = $this->payrollPeriod($date);
        if ($date->betweenIncluded($period['start'], $period['end'])) {
            return $period['end']->copy()->startOfMonth();
        }

        $startDay = $this->payrollPeriodStartDay();
        $endDay = $this->payrollPeriodEndDay();
        if ($startDay > $endDay && $date->day >= $startDay) {
            return $date->copy()->addMonthNoOverflow()->startOfMonth();
        }
        if ($startDay <= $endDay && $date->day < $startDay) {
            return $date->copy()->subMonthNoOverflow()->startOfMonth();
        }

        return $date->copy()->startOfMonth();
    }
    public function grace(): int { return (int) $this->value('grace_minutes', '15'); }
    public function absenceCutoff(): string { return $this->value('absence_cutoff_time', $this->end()); }

    /** Start of the night window used to split day/night overtime (default 19:00). */
    public function nightStart(): string { return $this->value('night_start', '19:00'); }

    /** End of the night window used to split day/night overtime (default 07:00). */
    public function nightEnd(): string { return $this->value('night_end', '07:00'); }

    public function unpaidLeaveAsDeduction(): bool
    {
        return filter_var($this->value('unpaid_leave_as_deduction', '1'), FILTER_VALIDATE_BOOLEAN);
    }

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
        return isset($this->holidayDates()[$date->toDateString()]);
    }

    /** @return array<string, true> official holiday dates (Y-m-d) of the current company */
    private function holidayDates(): array
    {
        $cacheKey = $this->companyKey();
        if (isset($this->holidayCache[$cacheKey])) {
            return $this->holidayCache[$cacheKey];
        }
        if (!Schema::hasTable('hr_holidays')) {
            return $this->holidayCache[$cacheKey] = [];
        }

        $query = DB::table('hr_holidays');
        $companyId = CompanyContext::id();
        if ($companyId) $query->where('company_id', $companyId);

        $dates = [];
        foreach ($query->pluck('holiday_date') as $date) {
            $dates[Carbon::parse($date)->toDateString()] = true;
        }

        return $this->holidayCache[$cacheKey] = $dates;
    }
}
