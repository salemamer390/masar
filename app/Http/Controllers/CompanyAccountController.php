<?php

namespace App\Http\Controllers;

use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;

class CompanyAccountController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return redirect()->route('subscriptions.index');
        }

        abort_unless($user->company_id, 403, 'الحساب غير مرتبط بشركة.');

        $subscription = CompanySubscription::with('plan')
            ->where('company_id', $user->company_id)
            ->latest('id')
            ->first();
        $employeeCount = Employee::where('company_id', $user->company_id)
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', ['منتهي الخدمة', 'موقوف', 'قيد التعيين', 'Terminated', 'Suspended', 'Pending']))
            ->count();
        $users = User::with('role')
            ->where('company_id', $user->company_id)
            ->latest('id')
            ->get();
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('employee_limit')->get();
        $renewals = SubscriptionRenewalRequest::with('plan')
            ->where('company_id', $user->company_id)
            ->latest()
            ->limit(10)
            ->get();

        return view('company.account', compact('subscription', 'employeeCount', 'users', 'plans', 'renewals'));
    }
}
