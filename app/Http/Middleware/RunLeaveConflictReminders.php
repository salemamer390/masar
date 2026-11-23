<?php

namespace App\Http\Middleware;

use App\Services\LeaveAttendanceConflictService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Hosting without cron cannot run the scheduler, so reminders are also driven by
 * normal page requests: after the response is sent, at most once per hour per
 * company, the reminder pass runs for the signed-in user's company.
 */
class RunLeaveConflictReminders
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        $companyId = (int) $request->user()?->company_id;
        if (!$companyId || !$request->isMethod('GET')) return;
        if (!Cache::add("leave-conflict-reminders:{$companyId}", 1, now()->addHour())) return;
        try {
            app(LeaveAttendanceConflictService::class)->notify(null, $companyId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
