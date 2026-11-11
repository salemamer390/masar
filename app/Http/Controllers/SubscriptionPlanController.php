<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\ActivityLog;
use App\Notifications\PlatformCompanyAnnouncement;
class SubscriptionPlanController extends Controller
{
    public function index()
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
        $subscriptionActivity = ActivityLog::with('user')
            ->where('module', 'subscriptions')
            ->latest()
            ->limit(30)
            ->get();
        return view('subscriptions.index', compact(
            'plans', 'companies', 'renewalRequests', 'activeCompanies',
            'activeSubscriptions', 'expiringSubscriptions', 'pendingRenewals',
            'approvedRevenue', 'companiesByPlan', 'subscriptionActivity'
        ));
    }
    public function store(Request $request)
    {
        $data = $request->validate(['name'=>'required|string|max:100','employee_limit'=>'required|integer|min:1|max:1000000','hr_user_limit'=>'nullable|integer|min:0|max:1000','price'=>'nullable|numeric|min:0','billing_period'=>'required|in:monthly,yearly','features'=>'nullable|string']);
        $features = collect(preg_split('/[,\n]+/', (string) ($data['features'] ?? '')))->map(fn($v)=>trim($v))->filter()->values()->all();
        SubscriptionPlan::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::random(5),'employee_limit'=>$data['employee_limit'],'hr_user_limit'=>$data['hr_user_limit'] ?? 5,'price'=>$data['price'] ?? 0,'billing_period'=>$data['billing_period'],'features'=>$features,'sort_order'=>$data['employee_limit']]);
        return back()->with('toast',__('تمت إضافة الباقة بنجاح.'));
    }
    public function update(Request $request, SubscriptionPlan $plan)
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
            $this->createDefaultCompanyRoles($company->id);
            User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role_id' => $role->id,
                'company_id' => $company->id,
                'is_active' => true,
            ]);
            // بيانات المنشأة التأمينية خاصة بكل شركة، لذلك تبدأ الشركة الجديدة
            // بدون بيانات منشأة موروثة من شركة أخرى.
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
        if (!empty($data['ends_at']) && \Illuminate\Support\Carbon::parse($data['ends_at'])->startOfDay()->lt($startsAt)) {
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

    private function createDefaultCompanyRoles(int $companyId): void
    {
        app(\App\Services\DefaultCompanyRoles::class)->ensureForCompany($companyId, true);
    }
}
