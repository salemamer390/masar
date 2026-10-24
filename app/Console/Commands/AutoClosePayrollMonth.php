<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Salary;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Generates, approves and locks the payroll of every company when its month ends.
 * Scheduled daily; it acts only on the closing day (month end + configured grace days)
 * unless --month is given to close a specific month by hand.
 */
class AutoClosePayrollMonth extends Command
{
    protected $signature = 'payroll:auto-close {--month= : الشهر بصيغة YYYY-MM لإقفاله يدويًا} {--company= : رقم شركة واحدة}';

    protected $description = 'إنشاء واعتماد وقفل مسير الرواتب تلقائيًا في آخر يوم من الشهر';

    public function handle(PayrollRunService $service): int
    {
        $month = $this->targetMonth();
        if (!$month) {
            $this->info('اليوم ليس يوم إقفال المسير.');
            return self::SUCCESS;
        }

        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->pluck('id');
        $failed = 0;

        foreach ($companies as $companyId) {
            if (!Salary::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
                $this->line("الشركة {$companyId}: لا توجد هياكل رواتب، تم التخطي.");
                continue;
            }

            try {
                $run = $service->closeMonth((int) $companyId, $month);
                $this->info($run
                    ? "الشركة {$companyId}: تم إقفال مسير {$month->format('Y-m')} ({$run->status})."
                    : "الشركة {$companyId}: لا يوجد موظفون لهم رواتب في {$month->format('Y-m')}.");
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("الشركة {$companyId}: فشل الإقفال التلقائي - {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function targetMonth(): ?Carbon
    {
        if ($this->option('month')) {
            return Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth();
        }

        // Closing day = month end + grace days: today minus the grace must be a month's last day.
        $closingDay = Carbon::today()->subDays(max(0, (int) config('payroll.auto_close.days_after_month_end', 0)));

        return $closingDay->isLastOfMonth() ? $closingDay->copy()->startOfMonth() : null;
    }
}
