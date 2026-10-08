<?php

namespace App\Services;

use App\Models\PayrollRun;
use Carbon\Carbon;

class PayrollPeriodGuard
{
    /** Prevent source-data changes that would alter a locked payroll result. */
    public function ensureOpen(string|Carbon $date): void
    {
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        abort_if(
            PayrollRun::whereDate('payroll_month', $month)->where('status', 'Locked')->exists(),
            422,
            'This payroll period is locked. Create an authorized adjustment instead.'
        );
    }

    public function ensureRangeOpen(string|Carbon $from, string|Carbon $to): void
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $last = Carbon::parse($to)->startOfMonth();
        while ($cursor->lte($last)) {
            $this->ensureOpen($cursor);
            $cursor->addMonth();
        }
    }
}
