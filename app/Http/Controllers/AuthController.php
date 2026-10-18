<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\Role;
use App\Models\Permission;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\CompanySubscription;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
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

    public function registerCompany(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'owner_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $plan = SubscriptionPlan::query()
            ->where('employee_limit', 20)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$plan) {
            return back()->withErrors(['registration' => 'الباقة الافتراضية لحد 20 موظف غير متاحة حاليًا.'])->withInput();
        }

        $user = DB::transaction(function () use ($data, $plan): User {
            $baseSlug = Str::slug($data['company_name']) ?: 'company';
            $slug = $baseSlug;
            $suffix = 1;
            while (Company::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix++;
            }

            $company = Company::create([
                'name' => $data['company_name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => 'active',
            ]);

            CompanySubscription::create([
                'company_id' => $company->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => today(),
                'ends_at' => null,
                'trial_days' => 0,
            ]);

            $role = Role::firstOrCreate(
                ['company_id' => $company->id, 'name' => 'company_manager'],
                ['display_name' => 'مدير الشركة', 'description' => 'مالك الشركة ومدير حساباتها وصلاحياتها.']
            );
            $role->permissions()->sync(Permission::query()->pluck('id')->all());

            return User::create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $role->id,
                'company_id' => $company->id,
                'is_active' => true,
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('toast', 'تم إنشاء الشركة وحساب مديرها وتفعيل باقة 20 موظف بنجاح.');
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
