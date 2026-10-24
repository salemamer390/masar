<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class GlobalSuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user?->isSuperAdmin()) {
            return redirect()->route('dashboard')->with('error', 'هذه الشاشة متاحة لمدير المنصة غير المرتبط بشركة فقط.');
        }

        return $next($request);
    }
}
