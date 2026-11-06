<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MobileActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_active, 403, 'هذا الحساب معطل حاليًا.');
        return $next($request);
    }
}
