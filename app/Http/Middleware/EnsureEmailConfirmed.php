<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Locks (never deletes) a new account whose email stayed unconfirmed past the grace period. */
class EnsureEmailConfirmed
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->emailConfirmationLocked()
            && !$request->routeIs('account.locked', 'verification.send', 'logout', 'locale.switch')) {
            return redirect()->route('account.locked');
        }

        return $next($request);
    }
}
