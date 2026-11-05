<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Pure integer day-count logic for partial months.
 *
 * A month is paid on a fixed basis (30 days by default). Calendar days worked
 * are counted inclusively; a period that reaches the end of a short month
 * (February) is topped up to the basis so a full February is a full month.
 */
final class PayrollProration
{
    public const DEFAULT_BASIS = 30;

    public static function paidDays(Carbon $start, Carbon $end, int $basis = self::DEFAULT_BASIS): int
    {
        $basis = max(1, $basis);
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($end->lt($start)) return 0;

        // Count inclusively, month by month, so cross-month periods (26th to
        // 25th) accrue every day; the short-month top-up applies only at the
        // period's true end, exactly as it did for same-month periods.
        $paid = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $monthEnd = $cursor->copy()->endOfMonth();
            $segmentEnd = $monthEnd->lt($end) ? $monthEnd : $end;
            $segment = (int) $segmentEnd->day - (int) $cursor->day + 1;
            $daysInMonth = (int) $monthEnd->day;
            if ($segmentEnd->equalTo($end) && $segmentEnd->isSameDay($monthEnd) && $daysInMonth < $basis) {
                $segment += $basis - $daysInMonth;
            }
            $paid += $segment;
            $cursor = $monthEnd->copy()->addDay();
        }

        return max(0, min($basis, $paid));
    }

    public static function factor(Carbon $start, Carbon $end, int $basis = self::DEFAULT_BASIS): float
    {
        $basis = max(1, $basis);

        return self::paidDays($start, $end, $basis) / $basis;
    }
}
