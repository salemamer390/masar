@extends('layouts.app')
@section('title', 'حساب الشركة')
@section('content')
<div class="page-head">
    <div>
        <h1>حساب الشركة</h1>
        <p>ملخص الاشتراك والاستخدام وحسابات فريق الموارد البشرية.</p>
    </div>
    <a class="btn btn-primary" href="{{ route('users.index') }}">إدارة حسابات HR</a>
</div>

@if($subscription?->plan)
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="panel p-3"><small class="text-muted d-block">الباقة الحالية</small><strong>{{ $subscription->plan->name }}</strong></div></div>
    <div class="col-md-4"><div class="panel p-3"><small class="text-muted d-block">الموظفون المستخدمون</small><strong>{{ format_number($employeeCount) }} من {{ format_number($subscription->plan->employee_limit) }}</strong></div></div>
    <div class="col-md-4"><div class="panel p-3"><small class="text-muted d-block">حالة الاشتراك</small><strong>{{ $subscription->status === 'trial' ? 'فترة تجريبية' : 'نشط' }}</strong><span class="d-block small text-muted">{{ $subscription->ends_at ? 'ينتهي في '.$subscription->ends_at->format('d/m/Y') : 'بدون تاريخ انتهاء' }}</span></div></div>
</div>
@else
<div class="alert alert-warning">لا يوجد اشتراك مرتبط بالشركة حاليًا.</div>
@endif

@if(auth()->user()?->role?->name === 'hr_manager')
<div class="panel mb-4">
    <div class="panel-head"><div><h3>تجديد أو تغيير الباقة</h3><span>أرسل الطلب، وسيعتمده مدير المنصة بعد مراجعة السداد.</span></div></div>
    @if($renewals->contains('status', 'pending'))
        <div class="p-3 text-muted">يوجد طلب قيد المراجعة حاليًا.</div>
    @else
        <form method="POST" action="{{ route('company.renewals.store') }}" class="p-3 row g-3">@csrf
            <div class="col-md-4"><label class="form-label">الباقة المطلوبة</label><select name="plan_id" class="form-select" required>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($subscription?->subscription_plan_id === $plan->id)>{{ $plan->name }} — {{ number_format($plan->price, 2) }} ج.م / {{ $plan->billing_period === 'yearly' ? 'سنة' : 'شهر' }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">ملاحظة للسداد أو الطلب <small class="text-muted">(اختياري)</small></label><input name="note" class="form-control" maxlength="1000"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">إرسال الطلب</button></div>
        </form>
    @endif
</div>
@endif

<div class="panel table-panel mb-4">
    <div class="panel-head"><div><h3>سجل طلبات الاشتراك</h3></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>الباقة</th><th>القيمة</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>@forelse($renewals as $renewal)<tr><td>{{ $renewal->plan?->name }}</td><td>{{ number_format($renewal->amount, 2) }} ج.م</td><td>{{ ['pending'=>'قيد المراجعة','approved'=>'معتمد','rejected'=>'مرفوض'][$renewal->status] ?? $renewal->status }}</td><td>{{ $renewal->created_at->format('d/m/Y') }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">لا توجد طلبات سابقة.</td></tr>@endforelse</tbody></table></div>
</div>

<div class="panel table-panel">
    <div class="panel-head"><div><h3>حسابات الشركة</h3><span>يمكن لمدير الشركة تعديل الحسابات والصلاحيات من شاشة حسابات HR.</span></div></div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>الاسم</th><th>البريد الإلكتروني</th><th>الدور</th><th>الحالة</th></tr></thead><tbody>
    @forelse($users as $account)
        <tr><td>{{ $account->name }}</td><td>{{ $account->email }}</td><td>{{ $account->role?->display_name ?? $account->role?->name ?? '—' }}</td><td>{{ $account->is_active ? 'نشط' : 'موقوف' }}</td></tr>
    @empty
        <tr><td colspan="4" class="text-center text-muted">لا توجد حسابات.</td></tr>
    @endforelse
    </tbody></table></div>
</div>
@endsection
