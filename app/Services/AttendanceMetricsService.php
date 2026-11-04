<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Support\CompanyContext;
use Carbon\Carbon;

/** Centralized attendance calculations so settings changes can be propagated. */
class AttendanceMetricsService
{
    public function __construct(private readonly HrOperationalSettingsService $settings) {}

    public function metrics(Employee $employee, ?string $checkIn, ?string $checkOut, ?string $fallbackStatus = null): array
    {
        $start = $employee->shift_start ?: $this->settings->start();
        $end = $employee->shift_end ?: $this->settings->end();
        $shiftStart = $this->timeToMinutes($start);
        $shiftEnd = $this->timeToMinutes($end);
        $night = $shiftEnd <= $shiftStart;
        if ($night) $shiftEnd += 1440;
        $in = $checkIn ? $this->timeToMinutes($checkIn) : null;
        $out = $checkOut ? $this->timeToMinutes($checkOut) : null;
        // On an overnight shift, the early-morning clock belongs to the next
        // calendar day. Allow a pre-shift arrival on the attendance day.
        if ($night && $in !== null && $in < $shiftStart - 360) $in += 1440;
        if ($out !== null && (($in !== null && $out <= $in) || ($night && $in === null && $out < $shiftStart))) $out += 1440;
        $late = $in !== null && $in > $shiftStart + $this->settings->grace() ? $in - $shiftStart : 0;
        return [
            'late_minutes' => $late,
            'early_leave_minutes' => $out !== null ? max(0, $shiftEnd - $out) : 0,
            'worked_hours' => $in !== null && $out !== null ? round(max(0, $out - $in) / 60, 2) : null,
            'status' => ($checkIn || $checkOut) ? ($late > 0 ? 'Late' : 'Present') : ($fallbackStatus ?: 'Absent'),
        ];
    }

    public function recalculateCompany(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $updated = 0;
            // Dates of an archived (closed) period keep the values they were saved with.
            $closed = app(AttendancePeriodArchiveService::class)->closedRanges((int) CompanyContext::id());
            Attendance::withoutGlobalScopes()->with('employee')
                ->where('company_id', CompanyContext::id())
                ->where(function ($query) use ($closed) {
                    foreach ($closed as [$from, $to]) $query->whereNotBetween('attendance_date', [$from, $to]);
                })
                ->chunkById(500, function ($rows) use (&$updated): void {
                    foreach ($rows as $attendance) {
                        if (!$attendance->employee) continue;
                        $values = $this->metrics($attendance->employee, $attendance->check_in, $attendance->check_out, $attendance->status);
                        try {
                            $attendance->forceFill($values)->saveQuietly();
                            $updated++;
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                            // closed while we were running: keep its saved value
                        }
                    }
                });
            return $updated;
        });
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
