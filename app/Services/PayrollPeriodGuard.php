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
        $day = Carbon::parse($date)->toDateString();
        abort_if(
            PayrollRun::whereIn('status', ['Approved', 'Locked', 'Paid'])
                ->where(fn ($q) => $q
                    ->where(fn ($range) => $range->whereDate('period_start', '<=', $day)->whereDate('period_end', '>=', $day))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('period_start')->whereDate('payroll_month', $month)))
                ->exists(),
            422,
            'This payroll period is finalized. Create an authorized adjustment instead.'
        );
    }

    public function ensureRangeOpen(string|Carbon $from, string|Carbon $to): void
    {
        $cursor = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();
        abort_if(PayrollRun::whereIn('status', ['Approved', 'Locked', 'Paid'])
            ->whereDate('period_start', '<=', $last)->whereDate('period_end', '>=', $cursor)->exists(),
            422, 'This payroll date range overlaps a finalized period.');
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
