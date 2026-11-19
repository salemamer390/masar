<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\Penalty;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
use App\Models\User;
use App\Notifications\PlatformCompanyAnnouncement;
use App\Services\CompanyErasureService;
use App\Services\OwnerDatabaseUpdateService;
use App\Notifications\SubscriptionRenewalProcessedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * لوحة تحكم المالك الخاصة: إدارة الشركات والباقات والاشتراكات وطلبات التجديد
 * والإشعارات ونظرة على بيانات كل شركة. الوصول مقيد بميدلوير owner (حساب
 * المالك فقط) والصفحة غير مدرجة في القائمة الجانبية.
 */
class OwnerDashboardController extends Controller
{
    public function index(OwnerDatabaseUpdateService $databaseUpdates)
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->orderBy('employee_limit')->get();
        $companies = Company::with(['subscriptions.plan'])->latest()->get();
        $renewalRequests = SubscriptionRenewalRequest::with(['company', 'plan', 'requester'])->latest()->get();
        $latestSubscriptions = $companies->mapWithKeys(fn ($company) => [
            $company->id => $company->subscriptions->sortByDesc('id')->first(),
        ])->filter();
        $activeSubscriptionRecords = $latestSubscriptions->filter(
            fn ($subscription) => in_array($subscription->status, ['trial', 'active'], true)
        );

        $activeCompanies = Company::where('status', 'active')->count();
        $activeSubscriptions = $activeSubscriptionRecords->count();
        $expiringSubscriptions = $activeSubscriptionRecords
            ->filter(fn ($subscription) => $subscription->ends_at && $subscription->ends_at->between(today(), today()->copy()->addDays(30)))
            ->count();
        $pendingRenewals = $renewalRequests->where('status', 'pending')->count();
        $approvedRevenue = $renewalRequests->where('status', 'approved')->sum(fn ($request) => (float) $request->amount);
        $companiesByPlan = $activeSubscriptionRecords
            ->groupBy(fn ($subscription) => $subscription->plan?->name ?? 'بدون باقة')
            ->map->count();
        $totalEmployees = Employee::count();
        $totalUsers = User::whereNotNull('company_id')->count();

        // Data tab: read-only per-company record counts across the tenant tables.
        $dataStats = $this->perCompanyDataStats($companies->pluck('id')->all());
        $databaseUpdateStatus = $databaseUpdates->status();
        $subscriptionActivity = ActivityLog::with('user')
            ->whereIn('module', ['subscriptions', 'database'])
            ->latest()
            ->limit(30)
            ->get();

        $companyDetails = $this->companyDetails($companies, $latestSubscriptions, $renewalRequests);
        $pendingErasureFiles = app(CompanyErasureService::class)->pendingManifestCount();

        return view('owner.dashboard', compact(
            'plans', 'companies', 'renewalRequests', 'latestSubscriptions',
            'activeCompanies', 'activeSubscriptions', 'expiringSubscriptions',
            'pendingRenewals', 'approvedRevenue', 'companiesByPlan',
            'totalEmployees', 'totalUsers', 'dataStats', 'subscriptionActivity',
            'databaseUpdateStatus', 'companyDetails', 'pendingErasureFiles'
        ));
    }

    private function perCompanyDataStats(array $companyIds): array
    {
        if (!$companyIds) return [];
        $countBy = fn (string $table) => DB::table($table)
            ->whereIn('company_id', $companyIds)
            ->selectRaw('company_id, COUNT(*) as aggregate')
            ->groupBy('company_id')
            ->pluck('aggregate', 'company_id');

        $employees = $countBy('employees');
        $users = $countBy('users');
        $payrollRuns = $countBy('payroll_runs');
        $leaves = $countBy('leaves');
        $penalties = $countBy('penalties');
        $lastActivity = DB::table('activity_logs')
            ->whereIn('company_id', $companyIds)
            ->selectRaw('company_id, MAX(created_at) as last_at')
            ->groupBy('company_id')
            ->pluck('last_at', 'company_id');

        $stats = [];
        foreach ($companyIds as $companyId) {
            $stats[$companyId] = [
                'employees' => (int) ($employees[$companyId] ?? 0),
                'users' => (int) ($users[$companyId] ?? 0),
                'payroll_runs' => (int) ($payrollRuns[$companyId] ?? 0),
                'leaves' => (int) ($leaves[$companyId] ?? 0),
                'penalties' => (int) ($penalties[$companyId] ?? 0),
                'last_activity' => $lastActivity[$companyId] ?? null,
            ];
        }
        return $stats;
    }


    /** Read-only details shown in the company details dialog. Never includes passwords, hashes or tokens. */
    private function companyDetails($companies, $latestSubscriptions, $renewalRequests): array
    {
        $ids = $companies->pluck('id')->all();
        if (!$ids) return [];
        $count = fn (string $table) => DB::table($table)->whereIn('company_id', $ids)->selectRaw('company_id, COUNT(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        $attendance = $count('attendance');
        $devices = $count('fingerprint_devices');
        $documents = $count('documents');
        $admins = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.company_id', $ids)->where('roles.name', 'company_manager')
            ->orderBy('users.id')->get(['users.id', 'users.company_id', 'users.name', 'users.email', 'users.is_active', 'users.last_login_at', 'users.created_at'])
            ->groupBy('company_id');
        $details = [];
        foreach ($companies as $company) {
            $id = $company->id;
            $sub = $latestSubscriptions[$id] ?? null;
            $details[$id] = [
                'admins' => $admins[$id] ?? collect(),
                'subscription' => $sub,
                'renewals' => $renewalRequests->where('company_id', $id)->count(),
                'attendance' => (int) ($attendance[$id] ?? 0),
                'devices' => (int) ($devices[$id] ?? 0),
                'documents' => (int) ($documents[$id] ?? 0),
            ];
        }
        return $details;
    }

    /** Read-only: what a full erase would remove right now. */
    public function erasurePreview(Company $company, CompanyErasureService $erasure)
    {
        try {
            $counts = $erasure->preview((int) $company->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('تعذر تجهيز المعاينة: ') . $e->getMessage()], 422);
        }
        return response()->json(['counts' => $counts]);
    }

    /** Permanently erase one company, its accounts, all its rows and uploaded files. */
    public function destroyCompany(Request $request, Company $company, CompanyErasureService $erasure)
    {
        $data = $request->validate(['confirm_name' => ['required', 'string']]);
        if (!hash_equals((string) $company->name, (string) $data['confirm_name'])) {
            return back()->withErrors(['confirm_name' => __('اسم الشركة المكتوب غير مطابق. لم يتم حذف أي شيء.')]);
        }
        try {
            $result = $erasure->erase((int) $company->id);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['confirm_name' => __('تعذر حذف الشركة، ولم يتم حذف أي بيانات. ') . __('السبب: ') . class_basename($e)]);
        }
        if ($result['files_failed'] > 0) {
            return back()->with('toast', __('تم حذف بيانات الشركة من قاعدة البيانات، لكن تعذر حذف :n ملف. ما زالت قيد إعادة المحاولة ولم يكتمل الحذف الفعلي بعد.', ['n' => $result['files_failed']]));
        }
        return back()->with('toast', __('تم حذف الشركة وكل بياناتها وملفاتها نهائيًا.'));
    }

    public function retryErasureFiles(CompanyErasureService $erasure)
    {
        $left = $erasure->retryPendingFiles();
        return back()->with('toast', $left === 0 ? __('اكتمل حذف الملفات المتبقية.') : __('ما زال هناك ملفات تعذر حذفها، أعد المحاولة لاحقًا.'));
    }

    /** Set a new password for a company manager account. Nothing about the password is logged or flashed back. */
    public function resetManagerPassword(Request $request, Company $company)
    {
        $managers = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.company_id', $company->id)->where('roles.name', 'company_manager')
            ->orderBy('users.id')->pluck('users.id')->all();
        abort_if($managers === [], 422, __('لا يوجد حساب مدير لهذه الشركة.'));
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        $userId = (int) ($data['user_id'] ?? $managers[0]);
        abort_unless(in_array($userId, $managers, true), 422, __('الحساب المحدد ليس مدير هذه الشركة.'));
        $user = User::where('company_id', $company->id)->findOrFail($userId);
        // One transaction: the new password, the ended sessions and the durable reset event either all
        // persist or none do. Older sessions without a fingerprint are ended because of that event, so a
        // password that saved without it would leave them open.
        $sessions = DB::transaction(function () use ($user, $data, $company) {
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $ended = DB::table('sessions')->where('user_id', $user->id)->delete();
            ActivityLog::record('subscription_company_password_reset', "تمت إعادة تعيين كلمة مرور مدير الشركة {$company->name} ({$user->email}).", $user, null, null, 'subscriptions');
            return $ended;
        });
        return back()->with('toast', __('تم تعيين كلمة المرور الجديدة وتطبيقها فورًا. الجلسات التي تم إنهاؤها: :n.', ['n' => $sessions]));
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate(['name'=>'required|string|max:100','employee_limit'=>'required|integer|min:1|max:1000000','hr_user_limit'=>'nullable|integer|min:0|max:1000','price'=>'nullable|numeric|min:0','billing_period'=>'required|in:monthly,yearly','features'=>'nullable|string']);
        $features = collect(preg_split('/[,\n]+/', (string) ($data['features'] ?? '')))->map(fn($v)=>trim($v))->filter()->values()->all();
        SubscriptionPlan::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::random(5),'employee_limit'=>$data['employee_limit'],'hr_user_limit'=>$data['hr_user_limit'] ?? 5,'price'=>$data['price'] ?? 0,'billing_period'=>$data['billing_period'],'features'=>$features,'sort_order'=>$data['employee_limit']]);
        return back()->with('toast',__('تمت إضافة الباقة بنجاح.'));
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate(['name'=>'required|string|max:100','employee_limit'=>'required|integer|min:1|max:1000000','hr_user_limit'=>'nullable|integer|min:0|max:1000','price'=>'nullable|numeric|min:0','billing_period'=>'required|in:monthly,yearly','features'=>'nullable|string','is_active'=>'nullable|boolean']);
        $isActive = (bool) ($data['is_active'] ?? false);
        if (!$isActive && $plan->is_active && $plan->subscriptions()->whereIn('status', ['trial', 'active'])->exists()) {
            return back()->withErrors(['is_active' => __('لا يمكن إيقاف باقة مرتبطة باشتراكات نشطة.')])->withInput();
        }
        $data['hr_user_limit'] = $data['hr_user_limit'] ?? 5;
        $data['features'] = collect(preg_split('/[,\n]+/', (string) ($data['features'] ?? '')))->map(fn($v)=>trim($v))->filter()->values()->all();
        $plan->update($data + ['is_active' => $isActive]);
        return back()->with('toast',__('تم تحديث الباقة بنجاح.'));
    }

    public function storeCompany(Request $request)
    {
        $data = $request->validate([
            'name'=>'required|string|max:190',
            'email'=>'nullable|email|max:190',
            'phone'=>'nullable|string|max:50',
            'plan_id'=>'required|exists:subscription_plans,id',
            'trial_days'=>'nullable|integer|min:0|max:365',
            'admin_name'=>'required|string|max:150',
            'admin_email'=>'required|email|max:190|unique:users,email',
            'admin_password'=>'required|string|min:6',
        ]);
        $plan = SubscriptionPlan::query()
            ->whereKey($data['plan_id'])
            ->where('is_active', true)
            ->first();
        if (!$plan) {
            return back()->withErrors(['plan_id' => __('الباقة المختارة غير نشطة حاليًا.')])->withInput();
        }
        $company = null;
        DB::transaction(function () use ($data, $plan, &$company): void {
            $company = Company::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::random(5),'email'=>$data['email'] ?? null,'phone'=>$data['phone'] ?? null]);
            CompanySubscription::create(['company_id'=>$company->id,'subscription_plan_id'=>$plan->id,'status'=>($data['trial_days'] ?? 0) > 0 ? 'trial' : 'active','starts_at'=>today(),'ends_at'=>($data['trial_days'] ?? 0) > 0 ? today()->addDays((int)$data['trial_days']) : null,'trial_days'=>$data['trial_days'] ?? 0]);
            $role = Role::firstOrCreate(
                ['company_id' => $company->id, 'name' => 'company_manager'],
                ['display_name' => 'Company manager', 'description' => 'مدير الشركة وإدارة مستخدميها وأدوارها.'],
            );
            $role->permissions()->sync(Permission::query()->pluck('id')->all());
            app(\App\Services\DefaultCompanyRoles::class)->ensureForCompany($company->id, true);
            User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role_id' => $role->id,
                'company_id' => $company->id,
                'is_active' => true,
            ]);
        });
        // Company creation from the platform is an administrative operation;
        // initialise its payroll defaults here (public signup intentionally
        // keeps this work lazy to avoid gateway timeouts on shared hosting).
        app(\App\Services\PayrollDefaultsService::class)->ensureForCompany((int) $company->id);
        ActivityLog::record('subscription_company_create', "تم إنشاء شركة جديدة: {$company->name}.", $company, null, $company->toArray(), 'subscriptions');
        return back()->with('toast',__('تم إنشاء الشركة والاشتراك بنجاح.'));
    }

    public function updateCompanySubscription(Request $request, Company $company)
    {
        $data = $request->validate(['plan_id'=>'required|exists:subscription_plans,id','status'=>'required|in:trial,active,suspended,expired','ends_at'=>'nullable|date','trial_days'=>'nullable|integer|min:0|max:365']);
        $subscription = $company->subscriptions()->latest('id')->first();
        $plan = SubscriptionPlan::query()->whereKey($data['plan_id'])->first();
        $changingPlan = !$subscription || (int) $subscription->subscription_plan_id !== (int) $data['plan_id'];
        if (!$plan || ($changingPlan && !$plan->is_active)) {
            return back()->withErrors(['plan_id' => __('لا يمكن ربط الشركة بباقة غير نشطة.')])->withInput();
        }
        if (!$subscription) $subscription = new CompanySubscription(['company_id'=>$company->id, 'starts_at'=>today()]);
        $startsAt = $subscription->starts_at ? $subscription->starts_at->copy()->startOfDay() : today();
        if (!empty($data['ends_at']) && Carbon::parse($data['ends_at'])->startOfDay()->lt($startsAt)) {
            return back()->withErrors(['ends_at' => __('تاريخ الانتهاء لا يمكن أن يسبق تاريخ بداية الاشتراك.')])->withInput();
        }
        $subscription->fill(['subscription_plan_id'=>$data['plan_id'],'status'=>$data['status'],'ends_at'=>$data['ends_at'] ?? null,'trial_days'=>$data['trial_days'] ?? 0]);
        $subscription->save();
        ActivityLog::record('subscription_update', "تم تحديث اشتراك الشركة: {$company->name}.", $subscription, null, $subscription->toArray(), 'subscriptions');
        return back()->with('toast',__('تم تحديث اشتراك الشركة بنجاح.'));
    }

    public function updateCompanyStatus(Request $request, Company $company)
    {
        $data = $request->validate(['status'=>'required|in:active,suspended']);
        $company->update($data);
        ActivityLog::record('subscription_company_status', "تم تحديث حالة الشركة: {$company->name}.", $company, null, $company->toArray(), 'subscriptions');
        return back()->with('toast',__('تم تحديث حالة الشركة.'));
    }

    public function updateRenewal(Request $request, SubscriptionRenewalRequest $renewal)
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        abort_if($renewal->status !== 'pending', 422, __('تمت معالجة هذا الطلب بالفعل.'));
        if ($data['status'] === 'approved') {
            abort_unless(
                SubscriptionPlan::whereKey($renewal->subscription_plan_id)->where('is_active', true)->exists(),
                422,
                __('لا يمكن اعتماد طلب مرتبط بباقة غير نشطة.')
            );
        }

        DB::transaction(function () use ($renewal, $data, $request): void {
            $renewal->update([
                'status' => $data['status'],
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);
            if ($data['status'] !== 'approved') return;

            $start = today();
            $current = CompanySubscription::where('company_id', $renewal->company_id)->latest('id')->first();
            if ($current?->ends_at && $current->ends_at->isFuture()) $start = $current->ends_at->copy()->addDay();
            $end = $renewal->billing_period === 'yearly' ? $start->copy()->addYear()->subDay() : $start->copy()->addMonth()->subDay();

            CompanySubscription::create([
                'company_id' => $renewal->company_id,
                'subscription_plan_id' => $renewal->subscription_plan_id,
                'status' => 'active',
                'starts_at' => $start,
                'ends_at' => $end,
                'trial_days' => 0,
            ]);
        });

        $renewal->refresh();
        ActivityLog::record('subscription_renewal_process', $data['status'] === 'approved' ? 'تم اعتماد طلب الاشتراك.' : 'تم رفض طلب الاشتراك.', $renewal, null, $renewal->toArray(), 'subscriptions');
        $requester = $renewal->requester;
        if ($requester) $requester->notify(new SubscriptionRenewalProcessedNotification($renewal));

        return back()->with('toast', $data['status'] === 'approved' ? 'تم اعتماد التجديد وتفعيل الاشتراك.' : 'تم رفض طلب التجديد.');
    }

    public function sendAnnouncement(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:all,company'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        if ($data['audience'] === 'company' && empty($data['company_id'])) {
            return back()->withErrors(['company_id' => __('يرجى اختيار الشركة المستهدفة.')]);
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->when($data['audience'] === 'company', fn ($query) => $query->where('company_id', $data['company_id']))
            ->get();

        $recipients->each->notify(new PlatformCompanyAnnouncement($data['message']));

        ActivityLog::record(
            'company_announcement',
            $data['audience'] === 'all' ? 'Company-wide announcement sent.' : 'Company announcement sent.',
            null,
            null,
            ['audience' => $data['audience'], 'company_id' => $data['company_id'] ?? null, 'recipients' => $recipients->count()],
            'subscriptions'
        );

        return back()->with('toast', "تم إرسال الإشعار إلى {$recipients->count()} مستخدم.");
    }
}
