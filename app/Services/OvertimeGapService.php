<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\HrRequest;
use Carbon\Carbon;

/**
 * Days where an employee's attendance shows time outside their shift that no
 * submitted overtime request covers. This is the single source of truth for
 * the month-summary warning, the attendance-page warning, the overtime-review
 * page and the repeating reminder notifications: they must never disagree
 * about what counts as a gap.
 */
class OvertimeGapService
{
    /**
     * @return array<int, array{employee: Employee, date: string, day_minutes: int, night_minutes: int}>
     */
    public function collect($employeeQuery, Carbon $month): array
    {
        $period = app(PayrollRunService::class)->periodFor($month);
        $entries = [];
        $employeeQuery->chunkById(100, function ($employees) use (&$entries, $period) {
            $employeeIds = $employees->pluck('id');
            $attendance = Attendance::effective()->whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$period['start']->toDateString(), $period['end']->toDateString()])
                ->whereNotNull('check_in')->whereNotNull('check_out')
                ->get()->groupBy('employee_id');
            $requests = HrRequest::effective()->with('requestType')->whereIn('employee_id', $employeeIds)->get()
                ->filter(fn (HrRequest $request) => $this->belongsToPeriod($request, $period['start'], $period['end']))
                ->groupBy('employee_id');
            foreach ($employees as $employee) {
                $employeeRequests = $requests->get($employee->id, collect());
                foreach ($attendance->get($employee->id, collect()) as $item) {
                    $dayGap = $this->overtimeMinutes($item, $employee, 'day')
                        - $this->requestedMinutes($item, $employee, $employeeRequests, 'day');
                    $nightGap = $this->overtimeMinutes($item, $employee, 'night')
                        - $this->requestedMinutes($item, $employee, $employeeRequests, 'night');
                    if ($dayGap <= 0 && $nightGap <= 0) continue;
                    $entries[] = [
                        'employee' => $employee,
                        'date' => Carbon::parse($item->attendance_date)->toDateString(),
                        'day_minutes' => max(0, $dayGap),
                        'night_minutes' => max(0, $nightGap),
                    ];
                }
            }
        });

        return $entries;
    }

    /** Aggregate counts for the warning banners. */
    public static function summarize(array $gaps): array
    {
        return [
            'days' => count($gaps),
            'employees' => collect($gaps)->pluck('employee.id')->unique()->count(),
        ];
    }

    public function belongsToPeriod(HrRequest $request, Carbon $periodStart, Carbon $periodEnd): bool
    {
        $payload = is_array($request->payload) ? $request->payload : [];
        $start = $payload['start_date'] ?? $payload['date'] ?? $payload['request_date'] ?? null;
        $end = $payload['end_date'] ?? $start;
        if ($start) {
            try {
                return Carbon::parse($start)->lte($periodEnd)
                    && Carbon::parse($end)->gte($periodStart);
            } catch (\Throwable) {
                // Invalid optional payload dates fall back to created_at.
            }
        }
        return $request->created_at?->betweenIncluded($periodStart, $periodEnd) ?? false;
    }

    public function overtimeMinutes(Attendance $attendance, Employee $employee, string $period): int
    {
        if (!$attendance->check_in || !$attendance->check_out) return 0;
        $settings = app(HrOperationalSettingsService::class);
        $start = Carbon::parse($employee->shift_start ?: $settings->start());
        $end = Carbon::parse($employee->shift_end ?: $settings->end());
        $checkIn = Carbon::parse($attendance->check_in);
        $checkOut = Carbon::parse($attendance->check_out);
        $startMinutes = $start->hour * 60 + $start->minute;
        $endMinutes = $end->hour * 60 + $end->minute;
        $inMinutes = $checkIn->hour * 60 + $checkIn->minute;
        $outMinutes = $checkOut->hour * 60 + $checkOut->minute;
        if ($endMinutes <= $startMinutes) $endMinutes += 1440;
        if ($outMinutes <= $inMinutes) $outMinutes += 1440;
        $segments = [];
        if ($inMinutes < $startMinutes) $segments[] = [$inMinutes, min($startMinutes, $outMinutes)];
        if ($outMinutes > $endMinutes) $segments[] = [max($endMinutes, $inMinutes), $outMinutes];
        $nightStart = $this->timeToMinutes($settings->nightStart());
        $nightEnd = $this->timeToMinutes($settings->nightEnd());
        if ($nightEnd <= $nightStart) $nightEnd += 1440;
        $total = 0;
        foreach ($segments as [$from, $to]) {
            if ($to <= $from) continue;
            $night = 0;
            for ($day = -1; $day <= 1; $day++) {
                $offset = 1440 * $day;
                $night += max(0, min($to, $nightEnd + $offset) - max($from, $nightStart + $offset));
            }
            $total += $period === 'night' ? $night : max(0, ($to - $from) - $night);
        }
        return (int) round($total);
    }

    public function requestedMinutes(Attendance $attendance, Employee $employee, $requests, string $period): int
    {
        $actual = $this->overtimeMinutes($attendance, $employee, $period);
        if ($actual <= 0 || !$attendance->check_in || !$attendance->check_out) return 0;
        $date = Carbon::parse($attendance->attendance_date)->toDateString();

        $settings = app(HrOperationalSettingsService::class);
        $segments = OvertimeWindowMatcher::outsideShiftSegments(
            OvertimeWindowMatcher::timeToMinutes((string) $attendance->check_in) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) $attendance->check_out) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_start ?: $settings->start())) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_end ?: $settings->end())) ?? 0,
        );

        $authorized = 0;
        foreach ($requests as $request) {
            if (($request->requestType?->source_key ?: $request->request_type) !== 'overtime') continue;
            $payload = is_array($request->payload) ? $request->payload : [];
            $requestDate = $payload['request_date'] ?? $payload['overtime_request_date'] ?? $payload['date'] ?? null;
            if (!$requestDate) continue;
            try {
                if (Carbon::parse($requestDate)->toDateString() !== $date) continue;
            } catch (\Throwable) {
                continue;
            }
            if (($payload['overtime_period'] ?? $payload['period'] ?? null) !== $period) continue;
            $requested = (float) ($payload['hours'] ?? $payload['duration_hours'] ?? $payload['day_overtime_hours'] ?? $payload['night_overtime_hours'] ?? 0) * 60;
            if ($requested <= 0) continue;

            $from = OvertimeWindowMatcher::timeToMinutes($payload['from_time'] ?? null);
            $to = OvertimeWindowMatcher::timeToMinutes($payload['to_time'] ?? null);
            if ($from === null || $to === null) {
                // Without a written window, only a request marked to be placed
                // next to the shift earns anything (hours-only imports);
                // other legacy untimed requests still earn nothing.
                if (empty($payload['anchor_to_shift'])) continue;
                $authorized += OvertimeWindowMatcher::anchoredMinutes(
                    $segments,
                    OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_start ?: $settings->start())) ?? 0,
                    OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_end ?: $settings->end())) ?? 0,
                    $requested,
                    $period,
                );
                continue;
            }
            $authorized += OvertimeWindowMatcher::authorizedMinutes($segments, $from, $to, $requested);
        }

        return (int) min($actual, max(0, $authorized));
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
