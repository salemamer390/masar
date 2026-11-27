<?php

namespace App\Http\Middleware;

use App\Support\EmailConfirmation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** No cron on the free host: due reminders are sent after a normal page response, at most once per hour. */
class RunEmailConfirmationReminders
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if (!$request->isMethod('GET') || !Cache::add('email-confirmation-reminders', 1, now()->addHour())) return;
        try {
            EmailConfirmation::sendDueReminders();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
