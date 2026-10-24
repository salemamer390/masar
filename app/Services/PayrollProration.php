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
        $daysInMonth = (int) $end->copy()->endOfMonth()->day;
        $paid = (int) $end->day - (int) $start->day + 1;

        if ((int) $end->day === $daysInMonth && $daysInMonth < $basis) {
            $paid += $basis - $daysInMonth;
        }

        return max(0, min($basis, $paid));
    }

    public static function factor(Carbon $start, Carbon $end, int $basis = self::DEFAULT_BASIS): float
    {
        $basis = max(1, $basis);

        return self::paidDays($start, $end, $basis) / $basis;
    }
}
