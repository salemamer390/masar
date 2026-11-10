<?php

namespace App\Services\Payroll;

use App\Services\PayrollSettingsService;

class OvertimeEngine
{
    public function __construct(private ?PayrollSettingsService $settings = null)
    {
        $this->settings ??= new PayrollSettingsService();
    }

    public function calculate(float $gross, float $dayHours = 0, float $nightHours = 0, float $officialHolidayDays = 0, float $weeklyRestDays = 0): array
    {
        $gross = max(0, $gross);
        $dayHours = max(0, $dayHours);
        $nightHours = max(0, $nightHours);
        $officialHolidayDays = max(0, $officialHolidayDays);
        $weeklyRestDays = max(0, $weeklyRestDays);
        $hourly = $gross / $this->settings->hoursDivisor();
        $daily = $gross / $this->settings->daysDivisor();

        $day = $hourly * $dayHours * $this->settings->dayOvertimeMultiplier();
        $night = $hourly * $nightHours * $this->settings->nightOvertimeMultiplier();
        // The multiplier is the TOTAL days' wage for a worked official holiday
        // (2 = يومين إجمالًا). The month's salary already covers that day, so only
        // the difference above one day is added as an earning.
        $official = $daily * $officialHolidayDays * max(0.0, $this->settings->officialHolidayMultiplier() - 1);
        $weekly = $daily * $weeklyRestDays * $this->settings->weeklyRestMultiplier();

        return [
            'hourly_rate' => round($hourly, 6),
            'day_hours' => $dayHours,
            'night_hours' => $nightHours,
            'official_holiday_days' => $officialHolidayDays,
            'weekly_rest_days' => $weeklyRestDays,
            'day_overtime' => round($day, 2),
            'night_overtime' => round($night, 2),
            'official_holiday' => round($official, 2),
            'weekly_rest' => round($weekly, 2),
            'total_overtime' => round($day + $night + $official + $weekly, 2),
        ];
    }
}
