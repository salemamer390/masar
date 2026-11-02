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
        $late = $this->lateMinutes($checkIn, $start);
        return [
            'late_minutes' => $late,
            'early_leave_minutes' => $this->earlyLeaveMinutes($checkOut, $end),
            'worked_hours' => $this->workedHours($checkIn, $checkOut),
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

    private function lateMinutes(?string $checkIn, string $shiftStart): int
    {
        if (!$checkIn) return 0;
        $actual = $this->timeToMinutes($checkIn);
        $start = $this->timeToMinutes($shiftStart);
        return $actual > $start + $this->settings->grace() ? $actual - $start : 0;
    }

    private function earlyLeaveMinutes(?string $checkOut, string $shiftEnd): int
    {
        if (!$checkOut) return 0;
        return max(0, $this->timeToMinutes($shiftEnd) - $this->timeToMinutes($checkOut));
    }

    private function workedHours(?string $checkIn, ?string $checkOut): ?float
    {
        if (!$checkIn || !$checkOut) return null;
        $minutes = ($this->timeToMinutes($checkOut) - $this->timeToMinutes($checkIn) + 1440) % 1440;
        return round($minutes / 60, 2);
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
