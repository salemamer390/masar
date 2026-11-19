<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends a company user's open session once their password has changed (for example after an Owner
 * reset). Works on any session driver. Only the company `web` guard is covered.
 */
class EndSessionsAfterPasswordChange
{
    /** Fingerprint a session at login so it is covered from its first moment. */
    public static function fingerprint(\Illuminate\Contracts\Auth\Authenticatable $user): void
    {
        if (session()->isStarted()) {
            session()->put('password_hash_web_' . $user->getAuthIdentifier(), $user->getAuthPassword());
        }
    }

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
            // A session opened before this check existed has no fingerprint. If the Owner has reset this
            // account's password since (the reset is logged durably), that session predates the reset: end it.
            // Every session created after deploy is fingerprinted at login, so this only ever hits old ones.
            $resetLogged = ActivityLog::where('action', 'subscription_company_password_reset')
                ->where('model_type', get_class($user))->where('model_id', $user->getKey())->exists();
            if ($resetLogged) {
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();
                return redirect()->route('login');
            }
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
