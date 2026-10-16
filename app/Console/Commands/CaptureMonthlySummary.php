<?php

namespace App\Console\Commands;

use App\Http\Controllers\JobDataController;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class CaptureMonthlySummary extends Command
{
    protected $signature = 'summary:capture {--month= : الشهر بصيغة YYYY-MM، والافتراضي الشهر الحالي}';
    protected $description = 'حفظ ملخص الشهر تلقائيا لكل شركة';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->startOfMonth();

        $controller = app(JobDataController::class);
        $captured = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            $user = User::query()->where('company_id', $companyId)->first();
            if (!$user) {
                $this->warn("تم تخطي الشركة {$companyId}: لا يوجد مستخدم مرتبط بها.");
                continue;
            }

            Auth::login($user);
            $controller->captureSnapshot($month, $user->id);
            $captured++;
        }

        Auth::logout();
        $this->info("تم إنشاء/تحديث {$captured} سجل للشهر {$month->format('m/Y')}.");
        return self::SUCCESS;
    }
}
