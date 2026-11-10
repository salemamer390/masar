<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->isSuperAdmin()) {
            return redirect()->route('dashboard')
                ->with('error', __('هذه الشاشة متاحة لمدير المنصة فقط.'));
        }

        return $next($request);
    }
}
