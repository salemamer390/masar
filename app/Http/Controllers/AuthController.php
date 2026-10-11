<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\CompanySubscription;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'يرجى إدخال بريد إلكتروني صحيح.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ]);

        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            if (!$user->is_active) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'هذا الحساب معطل حاليًا. يرجى مراجعة مدير النظام.',
                ]);
            }
            if (!$user->isSuperAdmin()) {
                $subscription = $user->company_id ? CompanySubscription::where('company_id', $user->company_id)->whereIn('status', ['trial', 'active'])->latest('id')->first() : null;
                $message = !$user->company_id || !$user->company || $user->company->status !== 'active'
                    ? 'حساب الشركة غير نشط حاليًا. يرجى التواصل مع مدير المنصة.'
                    : (!$subscription ? 'لا يوجد اشتراك نشط لهذه الشركة. يرجى التواصل لتفعيل الاشتراك.' : (($subscription->ends_at && $subscription->ends_at->endOfDay()->lt(now())) ? 'انتهت مدة التجربة أو الاشتراك. يرجى التواصل مع مدير المنصة لتجديده.' : null));
                if ($message) {
                    if ($subscription && $subscription->ends_at && $subscription->ends_at->endOfDay()->lt(now())) $subscription->update(['status' => 'expired']);
                    Auth::logout();
                    return back()->withErrors(['email' => $message]);
                }
            }

            $user->update(['last_login_at' => now()]);
            ActivityLog::record('login', 'تم تسجيل الدخول بنجاح للنظام.');

            $request->session()->regenerate();
            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'email' => 'البيانات المدخلة غير صحيحة، يرجى المحاولة مرة أخرى.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        ActivityLog::record('logout', 'تم تسجيل الخروج من النظام.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
