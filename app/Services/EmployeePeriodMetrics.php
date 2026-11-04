<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** One day is classified once, for the attendance page, the monthly summary and open payroll. */
class EmployeePeriodMetrics
{
    public function __construct(private readonly HrOperationalSettingsService $settings) {}

    /**
     * Per-day classification over [$from, $to]. Each entry:
     * date, state (future|before_hire|after_termination|present|late|leave|mission|rest|holiday|absent|not_recorded),
     * records (Collection), has_record, present, explicit_absent, infer_missing,
     * late_minutes, early_leave_minutes, absence_days (fraction).
     */
    public function days(Employee $employee, Carbon $from, Carbon $to, Collection $attendance, Collection $leaves, Collection $requests): array
    {
        $days = [];
        $byDate = $attendance->groupBy(fn ($a) => $a->attendance_date->toDateString());
        $inferMissing = $this->infersMissing($employee);
        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $rows = $byDate->get($key, collect());
            $blank = ['date' => $key, 'records' => $rows, 'has_record' => $rows->isNotEmpty(), 'present' => false,
                'explicit_absent' => false, 'infer_missing' => $inferMissing,
                'late_minutes' => 0, 'early_leave_minutes' => 0, 'absence_days' => 0.0];
            if ($date->isFuture() && !$date->isToday()) { $days[] = array_merge($blank, ['state' => 'future']); continue; }
            if ($employee->hire_date && $date->lt($employee->hire_date->copy()->startOfDay())) { $days[] = array_merge($blank, ['state' => 'before_hire']); continue; }
            if ($employee->termination_date && $date->gt($employee->termination_date->copy()->startOfDay())) { $days[] = array_merge($blank, ['state' => 'after_termination']); continue; }
            $present = $rows->filter(fn ($a) => $a->check_in || $a->check_out || in_array($a->status, ['Present', 'Late', 'حاضر', 'متأخر'], true));
            $isPresent = $present->isNotEmpty();
            [$shiftStart, $shiftEnd] = $this->shift($employee, $date);
            $mission = false;
            $permissions = [];
            foreach ($requests as $request) {
                $source = $request->requestType?->source_key ?: $request->request_type;
                if (!in_array($source, ['permission', 'mission'], true)) continue;
                $p = $request->payload ?: [];
                $start = $p['start_date'] ?? $p['request_date'] ?? $p['date'] ?? $request->created_at?->toDateString();
                $end = $p['end_date'] ?? $start;
                if (!$start || $key < substr($start, 0, 10) || $key > substr($end, 0, 10)) continue;
                if ($source === 'mission') { $mission = true; continue; }
                // Untimed legacy permissions have no safe position within the shift.
                if (empty($p['from_time']) || empty($p['to_time'])) continue;
                $a = Carbon::parse($key.' '.$p['from_time']);
                $b = Carbon::parse($key.' '.$p['to_time']);
                if ($b->lte($a)) $b->addDay();
                // Try the same day and the following day; choose the placement
                // overlapping this shift, including windows straddling shift start.
                $best = [0, 0];
                foreach ([0, 1] as $offset) {
                    $window = [max($shiftStart->timestamp, $a->copy()->addDays($offset)->timestamp), min($shiftEnd->timestamp, $b->copy()->addDays($offset)->timestamp)];
                    if ($window[1] - $window[0] > $best[1] - $best[0]) $best = $window;
                }
                $permissions[] = $best;
            }
            $leave = $rows->contains(fn ($a) => in_array($a->status, ['On Leave', 'Leave', 'إجازة', 'اجازة'], true)) || $leaves->contains(fn ($l) => $l->start_date->toDateString() <= $key && $l->end_date->toDateString() >= $key);
            $late = 0;
            $early = 0;
            // Multiple same-day rows never multiply a day. Use the outer clock span.
            if ($isPresent && !$leave && !$mission) {
                $clockIns = $present->pluck('check_in')->filter()->map(fn ($time) => $this->clock($date, $time, $shiftStart, $shiftEnd))->sort();
                $clockOuts = $present->pluck('check_out')->filter()->map(fn ($time) => $this->clock($date, $time, $shiftStart, $shiftEnd))->sort();
                $late = max(0, (int) $present->max('late_minutes'));
                $early = max(0, (int) $present->max('early_leave_minutes'));
                if ($clockIns->isNotEmpty()) $late = max(0, $late - $this->covered($permissions, $shiftStart->timestamp, min($shiftEnd->timestamp, $clockIns->first()->timestamp)));
                if ($clockOuts->isNotEmpty()) $early = max(0, $early - $this->covered($permissions, max($shiftStart->timestamp, $clockOuts->last()->timestamp), $shiftEnd->timestamp));
            }
            $explicitAbsent = $rows->contains(fn ($a) => in_array($a->status, ['Absent', 'غائب', 'غياب'], true));
            $day = array_merge($blank, [
                'present' => $isPresent,
                'explicit_absent' => $explicitAbsent,
                'late_minutes' => $late,
                'early_leave_minutes' => $early,
            ]);
            if ($isPresent) { $days[] = array_merge($day, ['state' => $late > 0 ? 'late' : 'present']); continue; }
            if ($leave) { $days[] = array_merge($day, ['state' => 'leave']); continue; }
            if ($mission) { $days[] = array_merge($day, ['state' => 'mission']); continue; }
            if ($this->settings->isWeeklyRest($date)) { $days[] = array_merge($day, ['state' => 'rest']); continue; }
            if ($this->settings->isHoliday($date)) { $days[] = array_merge($day, ['state' => 'holiday']); continue; }
            $cutoff = Carbon::parse($key.' '.$this->settings->absenceCutoff());
            if ($shiftEnd->day !== $shiftStart->day && $cutoff->lt($shiftStart)) $cutoff->addDay();
            if (!$explicitAbsent && (!$inferMissing || now()->lt($cutoff))) { $days[] = array_merge($day, ['state' => 'not_recorded']); continue; }
            $shiftMinutes = max(1, (int) round(($shiftEnd->timestamp - $shiftStart->timestamp) / 60));
            $excused = $this->covered($permissions, $shiftStart->timestamp, $shiftEnd->timestamp);
            $days[] = array_merge($day, ['state' => 'absent', 'absence_days' => max(0, 1 - $excused / $shiftMinutes)]);
        }
        return $days;
    }

    /** Whether days without any record count as absence for this employee. */
    public function infersMissing(Employee $employee): bool
    {
        $status = $employee->status?->name;
        return in_array($status, ['Active', 'نشط', 'Leave', 'إجازة'], true)
            || ($employee->termination_date && in_array($status, ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة'], true));
    }

    public function calculate(Employee $employee, Carbon $from, Carbon $to, Collection $attendance, Collection $leaves, Collection $requests): array
    {
        $result = ['attendance_days' => 0, 'late_days' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'absence_days' => 0.0];
        foreach ($this->days($employee, $from, $to, $attendance, $leaves, $requests) as $day) {
            if ($day['present']) $result['attendance_days']++;
            $result['late_minutes'] += $day['late_minutes'];
            $result['early_leave_minutes'] += $day['early_leave_minutes'];
            if ($day['late_minutes'] > 0) $result['late_days']++;
            $result['absence_days'] += $day['absence_days'];
        }
        $result['absence_days'] = round($result['absence_days'], 4);
        return $result;
    }

    private function shift(Employee $employee, Carbon $date): array
    {
        $a = Carbon::parse($date->toDateString().' '.($employee->shift_start ?: $this->settings->start()));
        $b = Carbon::parse($date->toDateString().' '.($employee->shift_end ?: $this->settings->end()));
        if ($b->lte($a)) $b->addDay();
        return [$a, $b];
    }

    private function clock(Carbon $date, string $time, Carbon $start, Carbon $end): Carbon
    {
        $clock = Carbon::parse($date->toDateString().' '.$time);
        if ($end->toDateString() !== $start->toDateString() && $clock->timestamp < $start->timestamp - 360 * 60) $clock->addDay();
        return $clock;
    }

    /** Union, not sum, of excused minute intervals clipped to the missing span. */
    private function covered(array $windows, int $from, int $to): int
    {
        if ($to <= $from) return 0;
        sort($windows);
        $seconds = 0;
        $cursor = $from;
        foreach ($windows as [$a, $b]) {
            $a = max($a, $cursor, $from);
            $b = min($b, $to);
            if ($b > $a) { $seconds += $b - $a; $cursor = $b; }
        }
        return (int) round($seconds / 60);
    }
}
