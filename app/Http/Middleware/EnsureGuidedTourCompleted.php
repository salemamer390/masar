<?php

namespace App\Http\Middleware;

use App\Support\GuidedTour;
use Closure;
use Illuminate\Http\Request;

/**
 * The first-login tour is mandatory: until the user finishes it, every
 * request that would change data is refused. Reading pages stays possible
 * (the tour itself navigates between pages) and the front-end keeps the
 * user on the tour. Tour progress, logout and language switching stay open
 * so nobody can be locked out of the account.
 */
class EnsureGuidedTourCompleted
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! config('tour.enforce') || ! $user || $request->isMethodSafe()) {
            return $next($request);
        }

        // Column not migrated yet: nothing to enforce, never lock anyone out.
        if (! GuidedTour::isTracked() || $user->tour_seen_at !== null) {
            return $next($request);
        }

        if ($request->routeIs('tour.*', 'logout')) {
            return $next($request);
        }

        $message = __('أكمل الجولة التعريفية أولًا لتبدأ استخدام النظام.');

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 423);
        }

        return redirect()->back()->with('toast', $message);
    }
}
