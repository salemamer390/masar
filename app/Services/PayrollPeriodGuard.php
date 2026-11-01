<?php

namespace App\Services;

use App\Models\PayrollRun;
use Carbon\Carbon;

class PayrollPeriodGuard
{
    public function __construct(private readonly HrOperationalSettingsService $operationalSettings) {}

    /** Prevent source-data changes that would alter a locked payroll result. */
    public function ensureOpen(string|Carbon $date): void
    {
        $month = $this->operationalSettings
            ->payrollMonthForDate(Carbon::parse($date))
            ->toDateString();
        abort_if(
            PayrollRun::whereDate('payroll_month', $month)->where('status', 'Locked')->exists(),
            422,
            'This payroll period is locked. Create an authorized adjustment instead.'
        );
    }

    public function ensureRangeOpen(string|Carbon $from, string|Carbon $to): void
    {
        $cursor = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();
        $checked = [];
        while ($cursor->lte($last)) {
            $month = $this->operationalSettings->payrollMonthForDate($cursor)->toDateString();
            if (!isset($checked[$month])) {
                $this->ensureOpen($cursor);
                $checked[$month] = true;
            }
            $cursor->addDay();
        }
    }
}
