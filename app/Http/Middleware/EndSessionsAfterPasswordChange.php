<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends a company user's open session once their password has changed (for example after an Owner
 * reset). Works on any session driver. Only the company `web` guard is covered.
 */
class EndSessionsAfterPasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('web');
        $user = $request->hasSession() ? $guard->user() : null;
        if (!$user || !$user->getAuthPassword()) {
            return $next($request);
        }

        $key = 'password_hash_web_' . $user->getAuthIdentifier();
        $session = $request->session();
        if (!$session->has($key)) {
            $session->put($key, $user->getAuthPassword());
        } elseif (!hash_equals($session->get($key), $user->getAuthPassword())) {
            $guard->logout();
            $session->invalidate();
            $session->regenerateToken();
            return redirect()->route('login');
        }

        return $next($request);
    }
}
