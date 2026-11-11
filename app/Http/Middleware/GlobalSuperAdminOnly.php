<?php

namespace App\Http\Middleware;

use App\Support\OwnerGate;
use Closure;
use Illuminate\Http\Request;

/**
 * Platform-wide management (plans, companies, subscriptions across all
 * companies) is reserved for the separate platform owner account defined by
 * OwnerGate: a company-less super admin whose email is allowlisted in
 * OWNER_EMAILS. Company-linked super admins keep full management of their
 * own company but can no longer open these cross-company screens here; the
 * private owner dashboard remains the owner-only management surface.
 */
class GlobalSuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!OwnerGate::allows($request->user())) {
            return redirect()->route('dashboard')->with('error', __('إدارة كل الشركات والاشتراكات مخصصة لحساب مالك المنصة المنفصل فقط.'));
        }

        return $next($request);
    }
}
