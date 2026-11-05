<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Wrapper kept for existing operators: generates (when needed) and closes one month's
 * payroll per company through the same unified close path used by payroll:close-periods.
 * The automatic daily closing itself is payroll:close-periods, scheduled at 00:20 Cairo.
 */
class AutoClosePayrollMonth extends Command
{
    protected $signature = 'payroll:auto-close {--month= : الشهر بصيغة YYYY-MM لإقفاله يدويًا} {--company= : رقم شركة واحدة}';

    protected $description = 'إقفال مسير شهر محدد يدويًا عبر مسار القفل الموحد';

    public function handle(PayrollRunService $service): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();

        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->pluck('id');
        $failed = 0;

        foreach ($companies as $companyId) {
            try {
                $run = $service->closeMonth((int) $companyId, $month);
                $this->info($run
                    ? "الشركة {$companyId}: تم إقفال مسير {$month->format('Y-m')} ({$run->status})."
                    : "الشركة {$companyId}: لا يوجد موظفون لهم رواتب في {$month->format('Y-m')}.");
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("الشركة {$companyId}: فشل الإقفال - {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
