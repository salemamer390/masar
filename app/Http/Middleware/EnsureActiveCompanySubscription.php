<?php

namespace App\Http\Middleware;

use App\Models\CompanySubscription;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveCompanySubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || $user->isSuperAdmin()) return $next($request);

        // السماح بالخروج وفتح الحساب وتجديد الاشتراك حتى بعد انتهائه.
        if ($request->routeIs('logout', 'company.account', 'company.renewals.store')) return $next($request);

        if (!$user->company_id || !$user->company || $user->company->status !== 'active') {
            abort(403, 'حساب الشركة غير نشط حاليًا. يرجى التواصل مع مدير المنصة.');
        }

        $subscription = CompanySubscription::where('company_id', $user->company_id)
            ->whereIn('status', ['trial', 'active'])->latest('id')->first();
        if (!$subscription) {
            abort(403, 'لا يوجد اشتراك نشط لهذه الشركة. يرجى التواصل لتفعيل الاشتراك.');
        }

        if ($subscription->ends_at && $subscription->ends_at->endOfDay()->lt(now())) {
            $subscription->update(['status' => 'expired']);
            abort(403, 'انتهت مدة التجربة أو الاشتراك. يرجى التواصل مع مدير المنصة لتجديده.');
        }

        return $next($request);
    }
}
