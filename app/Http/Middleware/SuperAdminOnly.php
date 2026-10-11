<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'هذه الشاشة متاحة لمدير المنصة فقط.');
        return $next($request);
    }
}
