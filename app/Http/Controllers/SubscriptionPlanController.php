<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\ActivityLog;
class SubscriptionPlanController extends Controller
{
    public function index()
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->orderBy('employee_limit')->get();
        $companies = Company::with(['subscriptions.plan'])->latest()->get();
        $renewalRequests = SubscriptionRenewalRequest::with(['company', 'plan', 'requester'])->latest()->get();
        $activeCompanies = Company::where('status', 'active')->count();
        $activeSubscriptions = CompanySubscription::whereIn('status', ['trial', 'active'])->count();
        $expiringSubscriptions = CompanySubscription::whereIn('status', ['trial', 'active'])
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [today(), today()->copy()->addDays(30)])
            ->count();
        $pendingRenewals = $renewalRequests->where('status', 'pending')->count();
        $approvedRevenue = $renewalRequests->where('status', 'approved')->sum(fn ($request) => (float) $request->amount);
        $companiesByPlan = CompanySubscription::with('plan')
            ->whereIn('status', ['trial', 'active'])
            ->get()
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
        return back()->with('toast','تمت إضافة الباقة بنجاح.');
    }
    public function update(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate(['name'=>'required|string|max:100','employee_limit'=>'required|integer|min:1|max:1000000','hr_user_limit'=>'nullable|integer|min:0|max:1000','price'=>'nullable|numeric|min:0','billing_period'=>'required|in:monthly,yearly','features'=>'nullable|string','is_active'=>'nullable|boolean']);
        $data['hr_user_limit'] = $data['hr_user_limit'] ?? 5;
        $data['features'] = collect(preg_split('/[,\n]+/', (string) ($data['features'] ?? '')))->map(fn($v)=>trim($v))->filter()->values()->all();
        $plan->update($data + ['is_active'=>(bool)($data['is_active'] ?? false)]);
        return back()->with('toast','تم تحديث الباقة بنجاح.');
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
        $company = null;
        DB::transaction(function () use ($data, &$company): void {
            $company = Company::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::random(5),'email'=>$data['email'] ?? null,'phone'=>$data['phone'] ?? null]);
            CompanySubscription::create(['company_id'=>$company->id,'subscription_plan_id'=>$data['plan_id'],'status'=>($data['trial_days'] ?? 0) > 0 ? 'trial' : 'active','starts_at'=>today(),'ends_at'=>($data['trial_days'] ?? 0) > 0 ? today()->addDays((int)$data['trial_days']) : null,'trial_days'=>$data['trial_days'] ?? 0]);
            $roleId = Role::where('name', 'hr_manager')->value('id');
            if (!$roleId) throw new \RuntimeException('دور مدير الموارد البشرية غير موجود.');
            User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role_id' => $roleId,
                'company_id' => $company->id,
                'is_active' => true,
            ]);
            $sourceId = Company::whereKeyNot($company->id)->oldest('id')->value('id');
            // بيانات المنشأة التأمينية خاصة بكل شركة، لذلك تبدأ الشركة الجديدة
            // بدون بيانات منشأة موروثة من شركة أخرى.
            foreach (['payroll_calculation_settings','payroll_allowance_settings','leave_policy_settings','hr_operational_settings','hr_holidays'] as $table) {
                if (!$sourceId) continue;
                $rows = DB::table($table)->where('company_id',$sourceId)->get();
                foreach ($rows as $row) { $copy=(array)$row; unset($copy['id']); $copy['company_id']=$company->id; $copy['created_at']=now(); $copy['updated_at']=now(); DB::table($table)->insert($copy); }
            }
        });
        ActivityLog::record('subscription_company_create', "تم إنشاء شركة جديدة: {$company->name}.", $company, null, $company->toArray(), 'subscriptions');
        return back()->with('toast','تم إنشاء الشركة والاشتراك بنجاح.');
    }
    public function updateCompanySubscription(Request $request, Company $company)
    {
        $data = $request->validate(['plan_id'=>'required|exists:subscription_plans,id','status'=>'required|in:trial,active,suspended,expired','ends_at'=>'nullable|date','trial_days'=>'nullable|integer|min:0|max:365']);
        $subscription = $company->subscriptions()->latest('id')->first();
        if (!$subscription) $subscription = new CompanySubscription(['company_id'=>$company->id, 'starts_at'=>today()]);
        $subscription->fill(['subscription_plan_id'=>$data['plan_id'],'status'=>$data['status'],'ends_at'=>$data['ends_at'] ?? null,'trial_days'=>$data['trial_days'] ?? 0]);
        $subscription->save();
        ActivityLog::record('subscription_update', "تم تحديث اشتراك الشركة: {$company->name}.", $subscription, null, $subscription->toArray(), 'subscriptions');
        return back()->with('toast','تم تحديث اشتراك الشركة بنجاح.');
    }
    public function updateCompanyStatus(Request $request, Company $company)
    {
        $data = $request->validate(['status'=>'required|in:active,suspended']);
        $company->update($data);
        ActivityLog::record('subscription_company_status', "تم تحديث حالة الشركة: {$company->name}.", $company, null, $company->toArray(), 'subscriptions');
        return back()->with('toast','تم تحديث حالة الشركة.');
    }
}
