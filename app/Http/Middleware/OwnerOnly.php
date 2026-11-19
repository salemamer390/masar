<?php

namespace App\Http\Middleware;

use App\Support\OwnerGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The private owner dashboard authenticates through its own `owner` guard so
 * the owner can stay logged in here and in a company account at the same
 * time. Anyone already logged into the regular app guard clearly is not the
 * owner: a 404 (not 403) keeps the page's existence invisible to them. Only
 * true guests are sent to the dedicated owner login.
 */
class OwnerOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (OwnerGate::allows(Auth::guard('owner')->user())) {
            // Owner actions run as the owner account (no company), never as a
            // company user who happens to be signed in on the web guard in the
            // same browser. Otherwise the tenant guard compared the owner's
            // target company with that unrelated company user and answered 403.
            // Company users keep their isolation: they never reach this branch.
            Auth::shouldUse('owner');
            return $next($request);
        }

        // Already authenticated on either guard but not the owner: 404.
        abort_if(Auth::guard('web')->check() || Auth::guard('owner')->check(), 404);

        return redirect()->route('owner.login');
    }
}
