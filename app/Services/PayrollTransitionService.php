<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PayrollRun;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll-cycle changes. When the configured period days move, the days between
 * the last calculated period and the first period of the new cycle become an
 * explicit transition payroll run: no gaps, no overlaps, clearly marked, and
 * reviewed/approved/closed like any other run. Finalized runs never move.
 */
class PayrollTransitionService
{
    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly PayrollSettingsService $settings,
        private readonly PayrollRunService $runs,
    ) {
    }

    /**
     * Create and fill the transition run for a cycle change. Returns null when
     * there is nothing to bridge (no calculated anchor or no gap).
     */
    public function createForCycleChange(int $companyId, int $newStartDay, int $newEndDay, int $oldStartDay, int $oldEndDay): ?PayrollRun
    {
        return CompanyContext::run($companyId, function () use ($companyId, $newStartDay, $newEndDay, $oldStartDay, $oldEndDay) {
            $anchor = $this->gapAnchor($companyId, $oldStartDay, $oldEndDay);
            if (!$anchor) return null;

            $firstNewStart = $this->firstNewPeriodStart($anchor, $newStartDay, $newEndDay);
            if (!$firstNewStart) return null;

            $gapEnd = $firstNewStart->copy()->subDay();
            if ($gapEnd->lt($anchor)) return null; // no gap: the new cycle follows on directly

            // Open drafts whose old-cycle period touches the gap are superseded:
            // they are canceled (kept, never deleted) before the transition revives those days.
            foreach ($this->staleDrafts($companyId, $oldStartDay, $oldEndDay, $anchor, $gapEnd) as $draft) {
                $draft->update(['status' => 'Canceled']);
                ActivityLog::record('payroll_run_cancel', 'مسير مسودة اتلغى بسبب تغيير دورة الرواتب واستبداله بفترة انتقالية.', $draft, ['status' => 'Draft'], ['status' => 'Canceled'], 'payroll');
            }
            // Drafts of the old chain that end before the gap keep covering their original
            // days: pin them to their old-cycle period so a later regeneration under the new
            // days never moves them onto days the transition (or a new-cycle run) covers.
            foreach ($this->oldChainDrafts($companyId, $oldStartDay, $oldEndDay, $anchor) as [$draft, $period]) {
                $draft->update([
                    'period_start' => $period['start']->toDateString(),
                    'period_end' => $period['end']->toDateString(),
                    // The close schedule follows the pinned period, not the old floating guess.
                    'closes_at' => $this->runs->closesAtFor($period['end'], $this->hrSettings->attendanceCorrectionDays())->toDateString(),
                ]);
                ActivityLog::record(
                    'payroll_run_period_pin',
                    'مسير مسودة اتثبت على فترته الأصلية من ' . $period['start']->format('d/m/Y') . ' إلى ' . $period['end']->format('d/m/Y') . ' قبل تغيير دورة الرواتب.',
                    $draft, null, ['period_start' => $period['start']->toDateString(), 'period_end' => $period['end']->toDateString()], 'payroll'
                );
            }

            $gapDays = $anchor->diffInDays($gapEnd) + 1;
            $run = PayrollRun::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                // Exact gap-start date keeps the run distinct from any monthly run of the same month.
                'payroll_month' => $anchor->toDateString(),
                'period_start' => $anchor->toDateString(),
                'period_end' => $gapEnd->toDateString(),
                'status' => 'Draft',
                'is_transition' => true,
                'created_by' => auth()->id(),
                'settings_snapshot' => $this->settings->snapshot(),
                'closes_at' => $this->runs->closesAtFor($gapEnd, $this->hrSettings->attendanceCorrectionDays())->toDateString(),
            ]);
            ActivityLog::record(
                'payroll_transition_create',
                "فترة انتقالية من {$anchor->format('d/m/Y')} إلى {$gapEnd->format('d/m/Y')} ({$gapDays} يوم) ناتجة عن تغيير دورة الرواتب.",
                $run, null, $run->toArray(), 'payroll'
            );

            // Fill the run: wages pro-rated by its actual days through the normal engine.
            return $this->runs->generate($anchor, null, $companyId, $run);
        });
    }

    /**
     * The first day no live payroll coverage exists for: after the latest of
     * stored run periods (finalized, pinned or transition), the charged record
     * windows of older finalized runs, floating drafts' old-cycle periods, and
     * saved attendance periods.
     */
    private function gapAnchor(int $companyId, int $oldStartDay, int $oldEndDay): ?Carbon
    {
        $covered = [];
        $runs = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', '!=', 'Canceled')->get(['id', 'status', 'payroll_month', 'period_end']);
        foreach ($runs as $run) {
            if ($run->period_end) {
                $covered[] = Carbon::parse($run->period_end)->startOfDay();
                continue;
            }
            if ($run->status === 'Draft') {
                // A floating draft currently previews its old-cycle period.
                $covered[] = $this->hrSettings->payrollPeriodForDays($run->payroll_month->copy()->startOfMonth(), $oldStartDay, $oldEndDay)['end']->copy()->startOfDay();
                continue;
            }
            // Finalized before exact periods were stored: the charged days live on its records.
            $maxEnd = collect(DB::table('payroll_records')->where('payroll_run_id', $run->id)->pluck('calculation_snapshot'))
                ->map(fn ($snapshot) => data_get(json_decode((string) $snapshot, true) ?: [], 'service_end'))->filter()->max();
            if ($maxEnd) $covered[] = Carbon::parse($maxEnd)->startOfDay();
        }
        if (!$covered) {
            // No payroll coverage at all: saved attendance only moves the anchor so the
            // transition bridges recent days instead of inventing back-pay for old history.
            $archiveEnd = DB::table('attendance_period_archives')->where('company_id', $companyId)->max('period_end');
            if ($archiveEnd) $covered[] = Carbon::parse($archiveEnd)->startOfDay();
        }
        $latest = collect($covered)->sort()->last();

        return $latest ? $latest->copy()->addDay() : null;
    }

    /** First period start under the new days that begins on or after the anchor. */
    private function firstNewPeriodStart(Carbon $anchor, int $startDay, int $endDay): ?Carbon
    {
        $month = $anchor->copy()->startOfMonth();
        for ($i = 0; $i < 25; $i++) {
            $period = $this->hrSettings->payrollPeriodForDays($month, $startDay, $endDay);
            if ($period['start']->gte($anchor)) {
                return $period['start']->copy()->startOfDay();
            }
            $month->addMonthNoOverflow();
        }

        return null;
    }

    /**
     * Floating monthly drafts (no stored period) whose old-cycle period ends before the
     * bridged gap: they belong to the old chain and must keep their original period.
     *
     * @return array<int, array{0: PayrollRun, 1: array{start: Carbon, end: Carbon}}>
     */
    private function oldChainDrafts(int $companyId, int $oldStartDay, int $oldEndDay, Carbon $anchor): array
    {
        $pinned = [];
        $drafts = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', 'Draft')->where('is_transition', false)
            ->whereNull('period_start')->whereNull('period_end')->get();
        foreach ($drafts as $draft) {
            $period = $this->hrSettings->payrollPeriodForDays($draft->payroll_month->copy()->startOfMonth(), $oldStartDay, $oldEndDay);
            if ($period['end']->lt($anchor)) {
                $pinned[] = [$draft, $period];
            }
        }

        return $pinned;
    }

    /** Open drafts whose old-cycle period intersects the bridged gap. */
    private function staleDrafts(int $companyId, int $oldStartDay, int $oldEndDay, Carbon $anchor, Carbon $gapEnd): array
    {
        $stale = [];
        $drafts = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', 'Draft')->where('is_transition', false)->get();
        foreach ($drafts as $draft) {
            $period = $this->hrSettings->payrollPeriodForDays($draft->payroll_month->copy()->startOfMonth(), $oldStartDay, $oldEndDay);
            if ($period['start']->lte($gapEnd) && $period['end']->gte($anchor)) {
                $stale[] = $draft;
            }
        }

        return $stale;
    }
}
