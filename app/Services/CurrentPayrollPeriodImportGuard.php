<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Workbook imports never backfill an older payroll period: that payroll is
 * already closed. A data row dated before the period containing today is
 * rejected before anything is saved. This is an import-only cutoff; the
 * separate finalized-run guard still protects actual locked payroll runs.
 */
class CurrentPayrollPeriodImportGuard
{
    public function __construct(private readonly HrOperationalSettingsService $settings) {}

    public function currentStart(): Carbon
    {
        return $this->settings->payrollPeriod(Carbon::today())['start']->copy()->startOfDay();
    }

    public function isBeforeCurrentPeriod(string|Carbon $date): bool
    {
        return Carbon::parse($date)->startOfDay()->lt($this->currentStart());
    }

    public function ensureWithinCurrentPeriod(string|Carbon $date, string $label = 'السجل'): void
    {
        if ($this->isBeforeCurrentPeriod($date)) {
            throw new \RuntimeException("{$label} بتاريخ قبل بداية فترة المرتب الحالية ({$this->currentStart()->format('Y-m-d')})؛ مرتب تلك الفترة أُقفل بالفعل.");
        }
    }

    /** A range belongs to the current period only when its whole span does. */
    public function ensureRangeWithinCurrentPeriod(string|Carbon $from, string|Carbon $to, string $label = 'السجل'): void
    {
        $this->ensureWithinCurrentPeriod($from, $label);
    }
}
