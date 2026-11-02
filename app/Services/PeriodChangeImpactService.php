<?php

namespace App\Services;

use App\Models\AttendancePeriodArchive;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks a change of the payroll/attendance period days against days that were already
 * calculated: approved or locked payroll runs (their real charged dates) and saved attendance
 * periods (pending or closed archives). It only reports. It never changes, excludes or
 * reassigns anything; the caller rejects the settings save when `blocking` is not empty.
 */
class PeriodChangeImpactService
{
    public function __construct(private readonly HrOperationalSettingsService $settings) {}

    /**
     * @return array{blocking: array<int, array<string,string>>, notes: array<int, array<string,string>>}
     */
    public function detect(int $companyId, int $newStartDay, int $newEndDay, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return CompanyContext::run($companyId, function () use ($companyId, $newStartDay, $newEndDay, $today) {
            [$payrollBlocking, $payrollNotes] = $this->payroll($companyId, $newStartDay, $newEndDay);
            $attendanceBlocking = $this->attendance($companyId, $newStartDay, $newEndDay, $today);

            return ['blocking' => array_merge($payrollBlocking, $attendanceBlocking), 'notes' => $payrollNotes];
        });
    }

    /** Charged dates of approved/locked runs: the stored period, or for older runs the service windows stored on their records. */
    private function frozenRuns(int $companyId): array
    {
        $runs = [];
        foreach (DB::table('payroll_runs')->where('company_id', $companyId)->whereIn('status', ['Approved', 'Locked'])->get(['id', 'payroll_month', 'status', 'period_start', 'period_end']) as $run) {
            $from = null; $to = null;
            if ($run->period_start && $run->period_end) {
                // The exact period stored when the run was approved is what the run covers.
                $runs[] = ['month' => Carbon::parse($run->payroll_month)->startOfMonth(), 'from' => substr((string) $run->period_start, 0, 10), 'to' => substr((string) $run->period_end, 0, 10), 'status' => $run->status];
                continue;
            }
            DB::table('payroll_records')->where('payroll_run_id', $run->id)->orderBy('id')->select('id', 'calculation_snapshot')
                ->chunkById(500, function ($records) use (&$from, &$to) {
                    foreach ($records as $record) {
                        $snap = json_decode((string) $record->calculation_snapshot, true) ?: [];
                        if (empty($snap['service_start']) || empty($snap['service_end'])) continue;
                        $from = $from === null ? $snap['service_start'] : min($from, $snap['service_start']);
                        $to = $to === null ? $snap['service_end'] : max($to, $snap['service_end']);
                    }
                });
            if ($from && $to) {
                $runs[] = ['month' => Carbon::parse($run->payroll_month)->startOfMonth(), 'from' => $from, 'to' => $to, 'status' => $run->status];
            }
        }

        return $runs;
    }

    private function payroll(int $companyId, int $s, int $e): array
    {
        $blocking = []; $notes = [];
        $runs = $this->frozenRuns($companyId);
        if (!$runs) return [[], []];
        $frozenMonths = array_map(fn ($r) => $r['month']->format('Y-m'), $runs);
        $sorted = collect($runs)->sortBy(fn ($r) => $r['month']->format('Y-m'))->values();
        $first = $sorted->first()['month'];
        $last = $sorted->last()['month'];
        // Months before the first final run are never re-calculated, so they are not checked.
        $month = $first->copy()->startOfMonth();
        $until = $last->copy()->startOfMonth()->addMonths(3);
        // Months that already have a final run keep their calculated amounts. Every other month
        // would be calculated with the new days, so its period must not reuse a calculated day.
        for (; $month->lte($until); $month->addMonthNoOverflow()) {
            if (in_array($month->format('Y-m'), $frozenMonths, true)) continue;
            $p = $this->settings->payrollPeriodForDays($month->copy(), $s, $e);
            foreach ($runs as $r) {
                $from = max($p['start']->toDateString(), $r['from']); $to = min($p['end']->toDateString(), $r['to']);
                if ($from <= $to) {
                    $blocking[] = ['source' => 'payroll_run', 'from' => $from, 'to' => $to,
                        'text' => 'الأيام من ' . $this->d($from) . ' إلى ' . $this->d($to) . ' محسوبة في مسير ' . $r['month']->format('m/Y') . ' (' . ($r['status'] === 'Locked' ? 'مقفول' : 'معتمد') . ') وهتدخل كمان في فترة مسير ' . $month->format('m/Y') . ' بالإعدادات الجديدة.'];
                }
            }
        }
        // Not blocking: days that no run would cover. Reported only, nothing is invented for them.
        foreach ($runs as $r) {
            $next = $r['month']->copy()->addMonthNoOverflow();
            if (in_array($next->format('Y-m'), $frozenMonths, true)) continue;
            $p = $this->settings->payrollPeriodForDays($next, $s, $e);
            $gapFrom = Carbon::parse($r['to'])->addDay()->toDateString();
            if ($p['start']->toDateString() > $gapFrom) {
                $gapTo = $p['start']->copy()->subDay()->toDateString();
                $notes[] = ['source' => 'payroll_run', 'from' => $gapFrom, 'to' => $gapTo,
                    'text' => 'الأيام من ' . $this->d($gapFrom) . ' إلى ' . $this->d($gapTo) . ' مش داخلة في أي مسير بعد مسير ' . $r['month']->format('m/Y') . '.'];
            }
        }

        return [$blocking, $notes];
    }

    /** Follows the same chain the archive job will follow with the new days. */
    private function attendance(int $companyId, int $s, int $e, Carbon $today): array
    {
        $saved = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $companyId)->get();
        if ($saved->isEmpty()) return [];
        $cursor = Carbon::parse($saved->max('period_end'))->startOfDay()->addDay();
        $out = [];
        for ($guard = 0; $guard < 800; $guard++) {
            $p = $this->settings->payrollPeriodForDays($this->monthFor($cursor, $s, $e), $s, $e);
            $start = $p['start']->copy()->startOfDay(); $end = $p['end']->copy()->startOfDay();
            if ($end->lt($cursor)) { $cursor = $cursor->copy()->addDay(); continue; }
            foreach ($saved as $a) {
                $from = max($start->toDateString(), $a->period_start->toDateString());
                $to = min($end->toDateString(), $a->period_end->toDateString());
                if ($from <= $to) {
                    $out[] = ['source' => 'attendance_archive', 'from' => $from, 'to' => $to,
                        'text' => 'الأيام من ' . $this->d($from) . ' إلى ' . $this->d($to) . ' محفوظة في فترة الحضور ' . $a->period_start->format('d/m/Y') . ' - ' . $a->period_end->format('d/m/Y') . ' (' . ($a->status === AttendancePeriodArchive::CLOSED ? 'مقفولة' : 'منتهية وفي مهلة التصحيح') . ') وهتدخل كمان في فترة الحضور ' . $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y') . ' بالإعدادات الجديدة.'];
                }
            }
            if ($end->gte($today)) break;
            $cursor = $end->copy()->addDay();
        }

        return $out;
    }

    private function monthFor(Carbon $date, int $s, int $e): Carbon
    {
        $p = $this->settings->payrollPeriodForDays($date, $s, $e);
        if ($date->betweenIncluded($p['start'], $p['end'])) return $p['end']->copy()->startOfMonth();
        if ($s > $e && $date->day >= $s) return $date->copy()->addMonthNoOverflow()->startOfMonth();
        if ($s <= $e && $date->day < $s) return $date->copy()->subMonthNoOverflow()->startOfMonth();

        return $date->copy()->startOfMonth();
    }

    private function d(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }
}
