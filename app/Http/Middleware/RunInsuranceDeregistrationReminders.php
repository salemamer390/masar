<?php

namespace App\Http\Middleware;

use App\Services\InsuranceDeregistrationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Hosting without cron cannot run the scheduler, so the form 6 reminders are
 * also driven by normal page requests: after the response is sent, at most once
 * per hour per company.
 */
class RunInsuranceDeregistrationReminders
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        $companyId = (int) $request->user()?->company_id;
        if (!$companyId || !$request->isMethod('GET')) return;
        if (!Cache::add("insurance-deregistration-reminders:{$companyId}", 1, now()->addHour())) return;
        try {
            app(InsuranceDeregistrationService::class)->notify($companyId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
