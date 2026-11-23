<?php

namespace App\Services;

/**
 * Matches an overtime request's clock-time window against the employee's
 * actual fingerprint presence outside the shift on a given day.
 *
 * Amer's confirmed rule: an overtime request only counts for the time the
 * employee was actually present inside the requested window. A request for
 * one hour before work is covered only by fingerprint presence during that
 * hour before work, and a partial overlap earns the partial duration.
 *
 * All times are minutes from midnight on the attendance-day timeline, where
 * values above 1440 mean after midnight of the following day.
 */
final class OvertimeWindowMatcher
{
    /**
     * Minutes since midnight for a "H:i" or "H:i:s" value; null when the
     * value is missing or unparsable.
     */
    public static function timeToMinutes(?string $time): ?int
    {
        if ($time === null) return null;
        $parts = explode(':', trim($time));
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return null;
        $minutes = ((int) $parts[0]) * 60 + (int) $parts[1];
        return $minutes >= 0 && $minutes < 1440 ? $minutes : null;
    }

    /**
     * Presence segments outside the shift: [check-in, shift start) when the
     * employee came early, and (shift end, check-out] when they stayed late.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function outsideShiftSegments(int $checkIn, int $checkOut, int $shiftStart, int $shiftEnd): array
    {
        if ($shiftEnd <= $shiftStart) $shiftEnd += 1440;
        if ($checkOut <= $checkIn) $checkOut += 1440;

        $segments = [];
        if ($checkIn < $shiftStart) $segments[] = [$checkIn, min($shiftStart, $checkOut)];
        if ($checkOut > $shiftEnd) $segments[] = [max($shiftEnd, $checkIn), $checkOut];

        return array_values(array_filter($segments, fn (array $segment): bool => $segment[1] > $segment[0]));
    }

    /**
     * Minutes the requested window [from, to] overlaps the given presence
     * segments. A window with to <= from crosses midnight (22:00 to 01:00).
     * The window is also tried one day later so an after-midnight window
     * written as 00:30-02:00 still matches post-midnight presence; the best
     * single placement wins, so minutes are never double counted.
     *
     * @param array<int, array{0: int, 1: int}> $segments
     */
    public static function windowOverlapMinutes(array $segments, int $from, int $to): int
    {
        if ($to <= $from) $to += 1440;

        $best = 0;
        foreach ([[$from, $to], [$from + 1440, $to + 1440]] as [$a, $b]) {
            $overlap = 0;
            foreach ($segments as [$start, $end]) {
                $overlap += max(0, min($end, $b) - max($start, $a));
            }
            $best = max($best, $overlap);
        }

        return $best;
    }

    /**
     * Minutes an overtime request WITHOUT a written from/to window earns on a
     * day. The app places the requested hours itself, next to the shift:
     * a day request sits either immediately before the shift start
     * (start - hours .. start) or immediately after the shift end
     * (end .. end + hours); the better single placement against actual
     * presence wins, so one requested hour is never paid twice. Someone who
     * clocked in two hours early still earns only the one requested hour,
     * and only for the hour that touches the shift start.
     * A night request follows the same rule (confirmed by Amer): its hours
     * are placed at the shift edge and paid only for the presence inside.
     * A request with a written window never comes here: authorizedMinutes
     * uses it exactly as written.
     *
     * @param array<int, array{0: int, 1: int}> $segments presence outside the shift
     */
    public static function anchoredMinutes(array $segments, int $shiftStart, int $shiftEnd, float $requestedMinutes, string $period): int
    {
        $requested = (int) round($requestedMinutes);
        if ($requested <= 0) return 0;

        if ($shiftEnd <= $shiftStart) $shiftEnd += 1440;
        $best = 0;
        foreach ([[$shiftStart - $requested, $shiftStart], [$shiftEnd, $shiftEnd + $requested]] as [$a, $b]) {
            $overlap = 0;
            foreach ($segments as [$start, $end]) {
                $overlap += max(0, min($end, $b) - max($start, $a));
            }
            $best = max($best, $overlap);
        }

        return min($requested, $best);
    }

    /**
     * Minutes a timed overtime request earns on a day: the requested window's
     * overlap with actual outside-shift presence, capped at the requested
     * duration. Partial overlap earns the partial duration.
     *
     * @param array<int, array{0: int, 1: int}> $segments
     */
    public static function authorizedMinutes(array $segments, int $from, int $to, float $requestedMinutes): int
    {
        if ($requestedMinutes <= 0) return 0;
        return (int) min((int) round($requestedMinutes), self::windowOverlapMinutes($segments, $from, $to));
    }
}
