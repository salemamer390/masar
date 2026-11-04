<?php

namespace App\Services;

use App\Http\Controllers\AttendanceController;
use App\Models\Attendance;
use App\Models\AttendancePeriodArchive;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Saves the attendance of each finished payroll period as a read-only record. */
class AttendancePeriodArchiveService
{
    public function __construct(private readonly HrOperationalSettingsService $settings) {}

    /**
     * Keep the period chain up to date. Oldest first, however long ago a period ended
     * (no lookback limit, so downtime cannot lose one):
     *  - a period that has ended gets a "pending" record at once, which fixes its
     *    boundaries; corrections stay allowed until closes_on (grace days after its end);
     *  - a pending period whose grace days are over is closed: its attendance is saved
     *    and the dates become read-only everywhere.
     * The next period starts the day after the previous one ends and ends where the
     * period containing its first day ends under the settings in force now. Settings
     * changes call this first (PayrollSettingsController::updateOperational), so a
     * period that already ended keeps the boundaries it had. Nothing before the
     * baseline (the latest period still inside its correction days, on the very first run) is invented.
     *
     * @return array{opened:int, closed:int}
     */
    public function sync(int $companyId, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return CompanyContext::run($companyId, function () use ($companyId, $today) {
            return DB::transaction(function () use ($companyId, $today) {
                // One writer per company at a time (command, settings save, other workers).
                DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
                $this->settings->flush();

                $result = ['opened' => 0, 'closed' => 0];
                $cursor = $this->nextPeriodStart($companyId, $today);
                for ($guard = 0; $guard < 800; $guard++) {
                    // The exact period the settings define for this day (26/25 means 26..25); nothing is invented.
                    $period = $this->settings->periodContaining($cursor->copy());
                    $start = $period['start']->copy()->startOfDay();
                    $end = $period['end']->copy()->startOfDay();
                    if ($end->lt($cursor)) { $cursor = $cursor->copy()->addDay(); continue; } // a day the settings leave uncovered (e.g. 31st with end day 30)
                    if ($end->gte($today)) break; // still the current period
                    if ($this->openPeriod($companyId, $start, $end)) $result['opened']++;
                    $cursor = $end->copy()->addDay();
                }
                foreach (AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)
                    ->where('status', AttendancePeriodArchive::PENDING)->whereDate('closes_on', '<=', $today->toDateString())
                    ->orderBy('period_start')->get() as $pending) {
                    $this->close($pending);
                    $result['closed']++;
                }

                return $result;
            });
        });
    }

    /** Backwards-friendly helper: number of periods opened or closed. */
    public function archiveEndedPeriods(int $companyId, ?Carbon $today = null): int
    {
        $r = $this->sync($companyId, $today);

        return $r['opened'] + $r['closed'];
    }

    /** First day not yet covered by a pending or closed period. */
    public function nextPeriodStart(int $companyId, Carbon $today): Carbon
    {
        $last = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)->max('period_end');
        if ($last) return Carbon::parse($last)->startOfDay()->addDay();

        // First run: start at the latest period that has not yet passed its correction days.
        return $this->settings->periodContaining($today->copy()->subDays($this->settings->attendanceCorrectionDays() + 1))['start']->copy()->startOfDay();
    }

    /** Record that a period ended (pending, still correctable). Existing records are never touched. */
    public function openPeriod(int $companyId, Carbon $start, Carbon $end): ?AttendancePeriodArchive
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $exists = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->exists();
        if ($exists) return null;
        try {
            $archive = AttendancePeriodArchive::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => AttendancePeriodArchive::PENDING,
                'closes_on' => $end->copy()->addDays($this->settings->attendanceCorrectionDays() + 1)->toDateString(),
            ]);
            return $archive;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null; // another process recorded it first
        }
    }

    /** Save the attendance of a pending period and make it read-only. */
    public function close(AttendancePeriodArchive $archive): AttendancePeriodArchive
    {
        $companyId = (int) $archive->company_id;
        $start = $archive->period_start->copy();
        $end = $archive->period_end->copy();

        return CompanyContext::run($companyId, function () use ($archive, $companyId, $start, $end) {
          return DB::transaction(function () use ($archive, $companyId, $start, $end) {
            // Wait for in-flight attendance writers; new ones wait for us.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $archive = AttendancePeriodArchive::withoutGlobalScopes()->lockForUpdate()->findOrFail($archive->id);
            if ($archive->status === AttendancePeriodArchive::CLOSED) return $archive;
            $records = Attendance::withoutGlobalScopes()->with(['employee' => fn ($q) => $q->withoutGlobalScopes(), 'employee.department'])
                ->where('company_id', $companyId)
                ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('attendance_date')->orderBy('id')->get();
            app(AttendanceController::class)->decorateOvertimeCollection($records);

            $rows = $records->map(fn (Attendance $a) => [
                'date' => $a->attendance_date?->toDateString(),
                'employee_code' => $a->employee?->employee_code,
                'employee_name' => $a->employee?->full_name,
                'department' => $a->employee?->department?->name,
                'check_in' => $a->check_in,
                'check_out' => $a->check_out,
                'late_minutes' => (int) $a->late_minutes,
                'early_leave_minutes' => (int) $a->early_leave_minutes,
                'day_overtime_minutes' => (int) ($a->day_overtime_minutes ?? 0),
                'night_overtime_minutes' => (int) ($a->night_overtime_minutes ?? 0),
                'status' => $a->status,
                'source' => $a->source,
            ])->values()->all();

            $archive->forceFill([
                'status' => AttendancePeriodArchive::CLOSED,
                'rows' => $rows,
                'record_count' => count($rows),
                'employee_count' => $records->pluck('employee_id')->unique()->count(),
                'settings' => [
                    'payroll_period_start_day' => $this->settings->payrollPeriodStartDay(),
                    'payroll_period_end_day' => $this->settings->payrollPeriodEndDay(),
                    'default_shift_start' => $this->settings->start(),
                    'default_shift_end' => $this->settings->end(),
                    'grace_minutes' => $this->settings->grace(),
                    'night_start' => $this->settings->nightStart(),
                    'night_end' => $this->settings->nightEnd(),
                    'weekly_rest_pattern' => $this->settings->weeklyRestPattern(),
                    'pending_punch_exceptions' => \App\Models\AttendanceImportDay::where('resolution','pending')->whereBetween('work_date',[$start->toDateString(),$end->toDateString()])->count(),
                ],
                'captured_at' => now(),
            ])->save();

            return $archive;
          });
        });
    }

    /** Explicit helper (tests, manual runs): open then close one period at once. */
    public function archivePeriod(int $companyId, Carbon $start, Carbon $end): ?AttendancePeriodArchive
    {
        $created = $this->openPeriod($companyId, $start, $end);

        return $created ? $this->close($created) : null;
    }

    /** Shared lock on the company row, held until the caller's transaction ends. Closing takes it exclusively. */
    public function lockCompanyForWrite(int $companyId): void
    {
        DB::table('companies')->where('id', $companyId)->sharedLock()->first();
    }

    public function isClosed(?int $companyId, $date, bool $locking = false): bool
    {
        if (!$companyId || !$date) return false;
        $day = Carbon::parse($date)->toDateString();
        // Closed, or pending and past its deadline (closes_on 00:00): the deadline itself locks the dates,
        // even if the nightly job has not saved the record yet.
        $query = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('status', AttendancePeriodArchive::CLOSED)
                ->orWhere(fn ($p) => $p->where('status', AttendancePeriodArchive::PENDING)->whereDate('closes_on', '<=', today()->toDateString())))
            ->whereDate('period_start', '<=', $day)->whereDate('period_end', '>=', $day);
        // A locking read sees the latest committed state, not the transaction snapshot.
        if ($locking) $query->sharedLock();

        return $query->exists();
    }

    /** Abort (422) when a write would touch attendance of a closed period. */
    public function ensureDateOpen($date, ?int $companyId = null, bool $locking = false): void
    {
        $companyId ??= CompanyContext::id();
        if ($this->isClosed($companyId, $date, $locking)) {
            abort(422, 'This attendance period is closed. Its attendance can no longer be changed.');
        }
    }

    /** Periods that ended and can still be corrected (deadline not reached). */
    public function pendingPeriods(?int $companyId = null): \Illuminate\Support\Collection
    {
        $companyId ??= CompanyContext::id();

        return AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', AttendancePeriodArchive::PENDING)->whereDate('closes_on', '>', today()->toDateString())
            ->orderBy('period_start')->get();
    }

    /** @return array<int, array{0: string, 1: string}> [start, end] date ranges that are archived (closed) for the company */
    public function closedRanges(int $companyId): array
    {
        return AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('status', AttendancePeriodArchive::CLOSED)
                ->orWhere(fn ($p) => $p->where('status', AttendancePeriodArchive::PENDING)->whereDate('closes_on', '<=', today()->toDateString())))
            ->get(['period_start', 'period_end'])
            ->map(fn ($a) => [$a->period_start->toDateString(), $a->period_end->toDateString()])->all();
    }
}
