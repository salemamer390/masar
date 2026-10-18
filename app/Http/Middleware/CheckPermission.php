<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user) return redirect()->route('login');
        if ($user->hasPermission($permission)) return $next($request);

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => 'غير مصرح بتنفيذ هذا الإجراء.'], 403);
        }

        $fallback = $this->allowedFallback($request);
        if ($fallback) {
            return redirect()->route($fallback)->with('toast', 'ليس لديك صلاحية للوصول إلى هذه الصفحة.');
        }

        return redirect()->route('access.denied')->with('toast', 'لا توجد صفحات متاحة لهذا الحساب حاليًا.');
    }

    private function allowedFallback(Request $request): ?string
    {
        $currentRoute = $request->route()?->getName();
        $user = $request->user();
        $candidates = [
            ['dashboard.view', 'dashboard'],
            ['employees.view', 'employees.index'],
            ['attendance.view', 'attendance.index'],
            ['leaves.view', 'leaves.index'],
            ['payroll.view', 'payroll.index'],
            ['insurance.view', 'insurance.index'],
            ['reports.view', 'reports.index'],
            ['roles.view', 'roles.index'],
            ['users.view', 'users.index'],
            ['users.view', 'company.account'],
        ];

        foreach ($candidates as [$requiredPermission, $routeName]) {
            if ($routeName === $currentRoute || !$user->hasPermission($requiredPermission)) continue;
            if ($routeName === 'dashboard' && $user->role?->name === 'employee') continue;
            return $routeName;
        }

        return null;
    }
}
