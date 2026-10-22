<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CompanyManagerOnly
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->role?->name !== 'company_manager') {
            return redirect()->route('dashboard')
                ->with('error', 'حذف المسير المقفول متاح لمدير الشركة فقط.');
        }

        return $next($request);
    }
}
