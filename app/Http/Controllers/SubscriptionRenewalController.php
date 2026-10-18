<?php

namespace App\Http\Controllers;

use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Notifications\SubscriptionRenewalRequestedNotification;
use App\Notifications\SubscriptionRenewalProcessedNotification;
use App\Models\ActivityLog;

class SubscriptionRenewalController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->company_id, 403);
        abort_unless($user->role?->name === 'hr_manager', 403, 'طلب التجديد متاح لمدير الشركة فقط.');
        $data = $request->validate([
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if(SubscriptionRenewalRequest::where('company_id', $user->company_id)->where('status', 'pending')->exists(), 422, 'يوجد طلب تجديد أو ترقية قيد المراجعة بالفعل.');

        $plan = SubscriptionPlan::whereKey($data['plan_id'])->where('is_active', true)->firstOrFail();
        $renewal = SubscriptionRenewalRequest::create([
            'company_id' => $user->company_id,
            'subscription_plan_id' => $plan->id,
            'requested_by' => $user->id,
            'request_type' => 'renewal',
            'status' => 'pending',
            'amount' => $plan->price,
            'billing_period' => $plan->billing_period,
            'note' => $data['note'] ?? null,
        ]);
        User::whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
            ->get()
            ->each(fn (User $admin) => $admin->notify(new SubscriptionRenewalRequestedNotification($renewal)));
        ActivityLog::record('subscription_renewal_request', 'تم إرسال طلب تجديد أو ترقية اشتراك.', $renewal, null, $renewal->toArray(), 'subscriptions');

        return back()->with('toast', 'تم إرسال طلب التجديد للمراجعة. سيتم تفعيل الاشتراك بعد اعتماده.');
    }

    public function update(Request $request, SubscriptionRenewalRequest $renewal)
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        abort_if($renewal->status !== 'pending', 422, 'تمت معالجة هذا الطلب بالفعل.');
        if ($data['status'] === 'approved') {
            abort_unless(
                SubscriptionPlan::whereKey($renewal->subscription_plan_id)->where('is_active', true)->exists(),
                422,
                'لا يمكن اعتماد طلب مرتبط بباقة غير نشطة.'
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
}
