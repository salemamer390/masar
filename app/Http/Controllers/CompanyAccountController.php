<?php

namespace App\Http\Controllers;

use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
use App\Models\User;

class CompanyAccountController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() && !$user->company_id) {
            return redirect()->route('subscriptions.index');
        }

        abort_unless($user->company_id, 403, __('الحساب غير مرتبط بشركة.'));

        // Every query below is explicitly scoped to the authenticated company.
        $companyId = $user->company_id;

        $subscription = CompanySubscription::with('plan')
            ->where('company_id', $companyId)
            ->latest('id')
            ->first();

        $inactiveStatuses = [
            'منتهي الخدمة', 'منتهى الخدمة', 'موقوف', 'قيد التعيين',
            'Terminated', 'Suspended', 'Pending',
        ];

        $employeeCount = Employee::where('company_id', $companyId)
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', $inactiveStatuses))
            ->count();

        $users = User::with('role')
            ->where('company_id', $companyId)
            ->whereHas('role', fn ($query) => $query->where('name', '!=', 'company_manager'))
            ->latest('id')
            ->get();

        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('employee_limit')
            ->get();

        $renewals = SubscriptionRenewalRequest::with('plan')
            ->where('company_id', $companyId)
            ->latest()
            ->limit(10)
            ->get();

        return view('company.account', compact(
            'subscription', 'employeeCount', 'users', 'plans', 'renewals'
        ));
    }
}
