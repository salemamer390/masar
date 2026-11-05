<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OwnerOnly
{
    /**
     * The private owner dashboard answers 404 to everybody except the platform
     * owner's own account: a super admin with no company whose email is listed
     * in config('owner.emails'). A 404 (not 403) keeps the page's existence
     * invisible to any other user, including other super admins.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $emails = array_map('strtolower', array_filter((array) config('owner.emails', [])));

        $allowed = $user
            && $user->isSuperAdmin()
            && !$user->company_id
            && in_array(strtolower((string) $user->email), $emails, true);

        abort_unless($allowed, 404);

        return $next($request);
    }
}
