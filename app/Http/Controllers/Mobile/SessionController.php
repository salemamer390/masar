<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if ($user && !$user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $user = null;
        }
        return response()->json([
            'csrf_token' => csrf_token(),
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'company_id' => $user->company_id, 'role' => $user->role?->name] : null,
            'permissions' => $user ? Permission::pluck('name')->filter(fn ($p) => $user->hasPermission($p))->values() : [],
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($credentials, false)) {
            throw ValidationException::withMessages(['email' => ['البيانات المدخلة غير صحيحة.']]);
        }
        $user = $request->user();
        try {
            abort_unless($user->is_active, 403, 'هذا الحساب معطل حاليًا.');
            app(\App\Http\Middleware\EnsureActiveCompanySubscription::class)->handle($request, fn () => response()->noContent());
        } catch (\Throwable $e) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw $e;
        }
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        ActivityLog::record('login', 'تم تسجيل الدخول من تطبيق الموبايل.');
        return $this->show($request);
    }

    public function logout(Request $request)
    {
        if ($request->user()) ActivityLog::record('logout', 'تم تسجيل الخروج من تطبيق الموبايل.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true, 'csrf_token' => csrf_token()]);
    }
}
