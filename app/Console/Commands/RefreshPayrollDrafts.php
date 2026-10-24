<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class RefreshPayrollDrafts extends Command
{
    protected $signature = 'payroll:refresh-drafts {--month= : Month in YYYY-MM format (defaults to the current month)}';
    protected $description = 'Refresh all open payroll drafts for every company';

    public function handle(PayrollRunService $service): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : now()->startOfMonth();
        $refreshed = 0;
        $skipped = 0;

        Company::query()->pluck('id')->each(function ($companyId) use ($service, $month, &$refreshed, &$skipped): void {
            $run = PayrollRun::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereDate('payroll_month', $month->toDateString())
                ->first();

            if ($run && in_array($run->status, ['Approved', 'Locked', 'Paid'], true)) {
                $skipped++;
                return;
            }

            try {
                $service->generate($month->copy(), null, (int) $companyId);
                $refreshed++;
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Payroll refresh failed for company {$companyId}: {$exception->getMessage()}");
            }
        });

        $this->info("Payroll drafts refreshed: {$refreshed}; skipped locked runs: {$skipped}; month: {$month->format('Y-m')}.");
        return self::SUCCESS;
    }
}
