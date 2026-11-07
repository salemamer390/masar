<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayrollPeriodClosedNotification;
use App\Services\HrOperationalSettingsService;
use App\Services\PayrollRunService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Closes every period whose correction grace has fully passed: the period's attendance,
 * its saved summary, all of its runs and their employee salary records freeze together,
 * whether the run is a normal monthly one or a transition run. A period that has not
 * ended is never closed. Scheduled daily; manual close during the grace stays possible
 * through POST /payroll/close-period.
 */
class ClosePayrollPeriods extends Command
{
    protected $signature = 'payroll:close-periods {--company= : رقم شركة واحدة}';

    protected $description = 'قفل كل فترات المسير المنتهية بعد اكتمال مهلة التصحيح تلقائيًا';

    public function handle(PayrollRunService $service, HrOperationalSettingsService $hrSettings): int
    {
        $today = Carbon::today();
        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->pluck('id');
        $failed = 0;

        foreach ($companies as $companyId) {
            $grace = CompanyContext::run((int) $companyId, fn () => $hrSettings->attendanceCorrectionDays());
            $due = CompanyContext::run((int) $companyId, fn () => PayrollRun::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', ['Draft', 'Approved'])
                ->get()
                // Monthly runs store no period dates: their end comes from the cycle
                // settings, and runs created before close scheduling fall back to the
                // company grace.
                ->filter(function (PayrollRun $run) use ($service, $hrSettings, $grace, $today) {
                    if ($run->closes_at) return Carbon::parse($run->closes_at)->lte($today);
                    // Monthly runs store no period dates: the end comes from the cycle
                    // snapshotted at creation, not from the current settings.
                    $end = $service->periodForRun($run)['end'];
                    return $service->closesAtFor($end, $grace)->lte($today);
                }));

            foreach ($due as $run) {
                try {
                    $closed = $service->closeRun($run, true);
                    $this->info("الشركة {$companyId}: تم قفل مسير {$closed->payroll_month->format('Y-m')}" . ($closed->is_transition ? ' (انتقالي)' : '') . " ({$closed->status}).");
                    $this->notifyPeriodClosed($closed);
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("الشركة {$companyId}: فشل قفل مسير {$run->id} - {$e->getMessage()}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Tell the company's payroll users that the period froze: one notification
     * per run, tenant-scoped, permission-gated.
     */
    private function notifyPeriodClosed(PayrollRun $run): void
    {
        $recipients = User::with('role.permissions')->where('is_active', true)
            ->where('company_id', $run->company_id)->get()
            ->filter(fn (User $user) => $user->hasPermission('payroll.view'));
        foreach ($recipients as $recipient) {
            $alreadySent = $recipient->notifications()
                ->where('type', PayrollPeriodClosedNotification::class)
                ->where('data->payroll_run_id', $run->id)
                ->exists();
            if (! $alreadySent) {
                $recipient->notify(new PayrollPeriodClosedNotification($run));
            }
        }
    }
}
