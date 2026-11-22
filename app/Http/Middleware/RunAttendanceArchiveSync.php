<?php

namespace App\Http\Middleware;

use App\Services\AttendancePeriodArchiveService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Hosting without cron never runs attendance:archive-periods, so an ended period would
 * never get its pending record (the correction banner and link) or its close. The same
 * sync runs from normal page requests instead: at most once an hour per company, for the
 * signed-in user's company only. It does only what the command does: record ended
 * periods, and close those whose correction days are over. It never touches payroll.
 */
class RunAttendanceArchiveSync
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = (int) $request->user()?->company_id;
        if ($companyId && $request->isMethod('GET') && Cache::add("attendance-archive-sync:{$companyId}", 1, now()->addHour())) {
            try {
                app(AttendancePeriodArchiveService::class)->sync($companyId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $next($request);
    }
}
