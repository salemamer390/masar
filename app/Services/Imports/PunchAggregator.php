<?php

namespace App\Services\Imports;

use Carbon\Carbon;

/**
 * Groups raw punches (one row per punch) into employee-day attendance.
 *
 * Assignment is shift-aware, never calendar-first/last: each punch lands in
 * the shift window that contains it, where day D's window runs from
 * shift_start(D) - 4h to shift_end(D) + 4h, overnight-aware. A punch that
 * falls in no window is reported as an error instead of being guessed onto a
 * calendar date. Within a window the earliest punch is the check-in and the
 * latest the check-out; a single punch is a check-in only.
 */
class PunchAggregator
{
    private const WINDOW_PAD_MINUTES = 240;

    /**
     * @param array<int, array{key: string, at: Carbon, line: int|string}> $punches
     * @param callable(string): array{0: int, 1: int} $shiftFor returns [startMinute, endMinute] for an employee key
     * @return array{days: array<int, array{key: string, date: string, check_in: string, check_out: ?string}>, errors: array<int, string>}
     */
    public function aggregate(array $punches, callable $shiftFor): array
    {
        $byEmployee = [];
        foreach ($punches as $punch) {
            $byEmployee[$punch['key']][] = $punch;
        }

        $days = [];
        $errors = [];
        foreach ($byEmployee as $key => $employeePunches) {
            [$start, $end] = $shiftFor($key);
            if ($end <= $start) $end += 1440; // overnight shift
            usort($employeePunches, fn ($a, $b) => $a['at'] <=> $b['at']);
            $assigned = [];
            foreach ($employeePunches as $punch) {
                $date = $this->windowDate($punch['at'], $start, $end);
                if ($date === null) {
                    $errors[] = (string) $punch['line'];
                    continue;
                }
                if ($date === false) {
                    $errors[] = ((string) $punch['line']) . '|AMBIGUOUS';
                    continue;
                }
                $assigned[$date][] = $punch;
            }
            foreach ($assigned as $date => $times) {
                usort($times, fn ($a,$b) => $a['at'] <=> $b['at']);
                $days[] = [
                    'key' => $key,
                    'date' => $date,
                    'check_in' => $times[0]['at']->format('H:i'),
                    'check_out' => count($times) > 1 ? $times[count($times) - 1]['at']->format('H:i') : null,
                    'punch_count' => count($times),
                    'punches' => array_map(fn ($punch) => ['at'=>$punch['at']->format('Y-m-d H:i:s'),'ref'=>(string)$punch['line']], $times),
                ];
            }
        }
        return ['days' => $days, 'errors' => $errors];
    }

    /**
     * The day whose shift window contains the punch, checking nearby days.
     * Returns false when more than one window contains it: with long shifts
     * the padded windows overlap, and guessing a day would misdate the record.
     */
    private function windowDate(Carbon $at, int $start, int $end): string|false|null
    {
        $matches = [];
        foreach ([0, -1, 1] as $offset) {
            $day = $at->copy()->startOfDay()->addDays($offset);
            $windowStart = $day->copy()->addMinutes($start - self::WINDOW_PAD_MINUTES);
            $windowEnd = $day->copy()->addMinutes($end + self::WINDOW_PAD_MINUTES);
            if ($at->gte($windowStart) && $at->lt($windowEnd)) {
                $matches[] = $day->toDateString();
            }
        }
        if (count($matches) > 1) return false;
        return $matches[0] ?? null;
    }
}
