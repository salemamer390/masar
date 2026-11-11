@extends('layouts.owner')
@section('title',__('لوحة تحكم المالك'))
@section('content')
@php
 $statusLabels=['trial'=>__('تجربة'),'active'=>__('نشط'),'suspended'=>__('موقوف'),'expired'=>__('منتهي')];
 $renewalLabels=['pending'=>__('قيد المراجعة'),'approved'=>__('معتمد'),'rejected'=>__('مرفوض')];
 $requestTypeLabels=['renewal'=>__('تجديد'),'upgrade'=>__('ترقية'),'change'=>__('تغيير باقة')];
@endphp
<div class="owner-page">
 <div class="owner-heading">
  <div>
   <h1><i class="bi bi-shield-lock-fill"></i> {{ __('لوحة تحكم المالك') }}</h1>
   <p>{{ __('إدارة الشركات والباقات والاشتراكات والبيانات — صفحة خاصة، لا تظهر في القوائم ولا يفتحها سوى حسابك.') }}</p>
  </div>
  <span class="owner-private-badge"><i class="bi bi-lock-fill"></i> {{ __('وصول خاص') }}</span>
 </div>
 <div class="owner-tabs" role="tablist">
  <button class="owner-tab active" data-tab="overview"><i class="bi bi-grid-1x2"></i> {{ __('نظرة عامة') }}</button>
  <button class="owner-tab" data-tab="companies"><i class="bi bi-buildings"></i> {{ __('الشركات') }} <b>{{$companies->count()}}</b></button>
  <button class="owner-tab" data-tab="plans"><i class="bi bi-layers"></i> {{ __('الباقات') }} <b>{{$plans->count()}}</b></button>
  <button class="owner-tab" data-tab="requests"><i class="bi bi-arrow-repeat"></i> {{ __('طلبات التجديد') }} <b>{{$pendingRenewals}}</b></button>
  <button class="owner-tab" data-tab="data"><i class="bi bi-database"></i> {{ __('البيانات') }}</button>
  <button class="owner-tab" data-tab="notifications"><i class="bi bi-megaphone"></i> {{ __('الإشعارات') }}</button>
  <button class="owner-tab" data-tab="audit"><i class="bi bi-clock-history"></i> {{ __('سجل العمليات') }}</button>
 </div>

 <section class="owner-panel active" data-panel="overview">
  <div class="owner-stats">
   <article class="owner-stat green"><i class="bi bi-buildings-fill"></i><div><span>{{ __('الشركات النشطة') }}</span><strong>{{format_number($activeCompanies)}}</strong><small>{{ __('من إجمالي') }} {{format_number($companies->count())}}</small></div></article>
   <article class="owner-stat blue"><i class="bi bi-layers-fill"></i><div><span>{{ __('الاشتراكات النشطة') }}</span><strong>{{format_number($activeSubscriptions)}}</strong><small>{{ __('تجربة أو نشط') }}</small></div></article>
   <article class="owner-stat amber"><i class="bi bi-hourglass-split"></i><div><span>{{ __('تنتهي خلال 30 يومًا') }}</span><strong>{{format_number($expiringSubscriptions)}}</strong><small>{{ __('تحتاج متابعة') }}</small></div></article>
   <article class="owner-stat purple"><i class="bi bi-cash-stack"></i><div><span>{{ __('الإيرادات المعتمدة') }}</span><strong>{{number_format($approvedRevenue,2)}}</strong><small>{{ __('ج.م') }}</small></div></article>
   <article class="owner-stat rose"><i class="bi bi-inbox"></i><div><span>{{ __('طلبات قيد المراجعة') }}</span><strong>{{format_number($pendingRenewals)}}</strong><small>{{ __('طلبات التجديد') }}</small></div></article>
   <article class="owner-stat teal"><i class="bi bi-people-fill"></i><div><span>{{ __('إجمالي الموظفين') }}</span><strong>{{format_number($totalEmployees)}}</strong><small>{{ __('في كل الشركات') }}</small></div></article>
   <article class="owner-stat slate"><i class="bi bi-person-badge-fill"></i><div><span>{{ __('حسابات المستخدمين') }}</span><strong>{{format_number($totalUsers)}}</strong><small>{{ __('حسابات الشركات') }}</small></div></article>
  </div>
  <div class="owner-overview-grid">
   <article class="owner-card"><header><h2><i class="bi bi-pie-chart"></i> {{ __('توزيع الشركات حسب الباقة') }}</h2></header><div class="distribution-list">@forelse($companiesByPlan as $planName=>$count)<div><span>{{$planName}}</span><b>{{format_number($count)}}</b><div class="distribution-bar"><i style="width:{{$activeCompanies?min(100,($count/$activeCompanies)*100):0}}%"></i></div></div>@empty<p class="muted p-3">{{ __('لا توجد اشتراكات نشطة.') }}</p>@endforelse</div></article>
   <article class="owner-card"><header><h2><i class="bi bi-arrow-repeat"></i> {{ __('أحدث طلبات التجديد') }}</h2><button class="btn btn-light btn-sm" type="button" onclick="openOwnerTab('requests')">{{ __('عرض الكل') }}</button></header><div class="overview-list">@forelse($renewalRequests->take(5) as $renewal)<div><div><b>{{$renewal->company?->name}}</b><small>{{$renewal->plan?->name}} · {{number_format($renewal->amount,2)}} {{ __('ج.م') }}</small></div><span class="owner-status {{$renewal->status}}">{{$renewalLabels[$renewal->status]??$renewal->status}}</span></div>@empty<p class="muted p-3">{{ __('لا توجد طلبات تجديد.') }}</p>@endforelse</div></article>
  </div>
  <article class="owner-card table-card"><header><h2><i class="bi bi-activity"></i> {{ __('آخر نشاط') }}</h2><button class="btn btn-light btn-sm" type="button" onclick="openOwnerTab('audit')">{{ __('سجل العمليات') }}</button></header><div class="table-scroll"><table><thead><tr><th>{{ __('العملية') }}</th><th>{{ __('المستخدم') }}</th><th>{{ __('التفاصيل') }}</th><th>{{ __('التاريخ') }}</th></tr></thead><tbody>@forelse($subscriptionActivity->take(6) as $activity)<tr><td>{{$activity->action}}</td><td>{{$activity->user?->name??__('النظام')}}</td><td>{{$activity->description}}</td><td>{{$activity->created_at?->format('d/m/Y H:i')}}</td></tr>@empty<tr><td colspan="4" class="muted center">{{ __('لا توجد عمليات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="companies" hidden>
  <div class="section-toolbar"><div><h2>{{ __('الشركات المشتركة') }}</h2><p>{{ __('إنشاء الشركات وإدارة حالتها واشتراكاتها.') }}</p></div><button type="button" class="btn btn-primary" id="toggleCompanyForm"><i class="bi bi-plus-lg"></i> {{ __('إضافة شركة') }}</button></div>
  <article class="owner-card" id="company-form" hidden><header><h2><i class="bi bi-building-add"></i> {{ __('إضافة شركة واشتراك') }}</h2></header><form method="POST" action="{{route('owner.companies.store')}}" class="form-grid">@csrf<label>{{ __('اسم الشركة') }}<input name="name" required></label><label>{{ __('البريد الإلكتروني') }}<input type="email" name="email"></label><label>{{ __('الهاتف') }}<input name="phone"></label><label>{{ __('الباقة') }}<select name="plan_id" required>@foreach($plans as $plan)<option value="{{$plan->id}}">{{$plan->name}}</option>@endforeach</select></label><label>{{ __('مدة التجربة بالأيام') }}<input type="number" name="trial_days" value="0" min="0"></label><label>{{ __('اسم مدير الشركة') }}<input name="admin_name" required></label><label>{{ __('بريد المدير') }}<input type="email" name="admin_email" required></label><label>{{ __('كلمة مرور المدير') }}<input type="password" name="admin_password" minlength="6" required></label><button class="btn btn-primary full">{{ __('إنشاء الشركة والحساب') }}</button></form></article>
  <article class="owner-card table-card"><header><h2><i class="bi bi-buildings"></i> {{ __('قائمة الشركات') }}</h2></header><div class="table-tools"><input id="companySearch" placeholder="{{ __('البحث في الشركات...') }}"><select id="companyStatus"><option value="">{{ __('كل الحالات') }}</option><option value="active">{{ __('نشطة') }}</option><option value="suspended">{{ __('موقوفة') }}</option></select></div><div class="table-scroll"><table id="companiesTable"><thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('حالة الشركة') }}</th><th>{{ __('الاشتراك') }}</th><th>{{ __('الباقة') }}</th><th>{{ __('ينتهي في') }}</th><th>{{ __('الموظفون') }}</th><th>{{ __('المستخدمون') }}</th><th></th></tr></thead><tbody>@forelse($companies as $company)@php($subscription=$latestSubscriptions[$company->id] ?? null)<tr><td>{{$company->name}}</td><td data-status="{{$company->status}}"><span class="owner-status {{$company->status==='active'?'active':'suspended'}}">{{$company->status==='active'?__('نشطة'):__('موقوفة')}}</span></td><td><span class="owner-status {{$subscription?->status??'expired'}}">{{$statusLabels[$subscription?->status]??__('بدون اشتراك')}}</span></td><td>{{$subscription?->plan?->name??'—'}}</td><td>{{$subscription?->ends_at?->format('d/m/Y')??__('بدون تاريخ انتهاء')}}</td><td>{{format_number($dataStats[$company->id]['employees'] ?? 0)}}</td><td>{{format_number($dataStats[$company->id]['users'] ?? 0)}}</td><td><button class="btn btn-outline btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#companySubscription{{$company->id}}">{{ __('إدارة') }}</button></td></tr>@empty<tr><td colspan="8" class="muted center">{{ __('لا توجد شركات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="plans" hidden>
  <div class="section-toolbar"><div><h2>{{ __('الباقات') }}</h2><p>{{ __('إضافة وتعديل الباقات وحدود الاستخدام.') }}</p></div><button type="button" class="btn btn-primary" id="togglePlanForm"><i class="bi bi-plus-lg"></i> {{ __('إضافة باقة') }}</button></div>
  <article class="owner-card" id="plan-form" hidden><header><h2><i class="bi bi-layers"></i> {{ __('إضافة باقة') }}</h2></header><form method="POST" action="{{route('owner.plans.store')}}" class="form-grid">@csrf<label>{{ __('اسم الباقة') }}<input name="name" required></label><label>{{ __('حد الموظفين') }}<input type="number" name="employee_limit" min="1" required></label><label>{{ __('حد حسابات الموارد البشرية') }}<input type="number" name="hr_user_limit" min="0" value="5"></label><label>{{ __('السعر') }}<input type="number" step="0.01" name="price" min="0"></label><label>{{ __('دورة الفوترة') }}<select name="billing_period"><option value="monthly">{{ __('شهري') }}</option><option value="yearly">{{ __('سنوي') }}</option></select></label><label class="full">{{ __('المزايا') }}<textarea name="features" rows="2"></textarea></label><button class="btn btn-primary full">{{ __('إضافة الباقة') }}</button></form></article>
  <article class="owner-card table-card"><header><h2>{{ __('الباقات الحالية') }}</h2></header><div class="table-scroll"><table><thead><tr><th>{{ __('الباقة') }}</th><th>{{ __('حد الموظفين') }}</th><th>{{ __('حد HR') }}</th><th>{{ __('السعر') }}</th><th>{{ __('الدورة') }}</th><th>{{ __('الحالة') }}</th><th></th></tr></thead><tbody>@forelse($plans as $plan)<tr><td>{{$plan->name}}</td><td>{{number_format($plan->employee_limit)}}</td><td>{{number_format($plan->hr_user_limit??5)}}</td><td>{{number_format($plan->price,2)}} {{ __('ج.م') }}</td><td>{{$plan->billing_period==='yearly'?__('سنوي'):__('شهري')}}</td><td><span class="owner-status {{$plan->is_active?'active':'suspended'}}">{{$plan->is_active?__('نشطة'):__('موقوفة')}}</span></td><td><button class="btn btn-outline btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#editPlan{{$plan->id}}">{{ __('تعديل') }}</button></td></tr>@empty<tr><td colspan="7" class="muted center">{{ __('لا توجد باقات.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="requests" hidden>
  <div class="section-toolbar"><div><h2>{{ __('طلبات التجديد والترقية') }}</h2><p>{{ __('مراجعة الطلبات واتخاذ إجراء عليها.') }}</p></div></div>
  <article class="owner-card table-card"><div class="table-scroll"><table><thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الباقة المطلوبة') }}</th><th>{{ __('المبلغ') }}</th><th>{{ __('مقدم الطلب') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('الإجراء') }}</th></tr></thead><tbody>@forelse($renewalRequests as $renewal)<tr><td>{{$renewal->company?->name}}</td><td>{{$requestTypeLabels[$renewal->request_type]??$renewal->request_type}}</td><td>{{$renewal->plan?->name}}</td><td>{{number_format($renewal->amount,2)}} {{ __('ج.م') }}</td><td>{{$renewal->requester?->name??'—'}}</td><td><span class="owner-status {{$renewal->status}}">{{$renewalLabels[$renewal->status]??$renewal->status}}</span></td><td>@if($renewal->status==='pending')<form method="POST" action="{{route('owner.renewals.update',$renewal)}}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="status" value="approved"><button class="btn btn-success btn-sm">{{ __('اعتماد') }}</button></form><form method="POST" action="{{route('owner.renewals.update',$renewal)}}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="status" value="rejected"><button class="btn btn-outline-danger btn-sm">{{ __('رفض') }}</button></form>@else—@endif</td></tr>@empty<tr><td colspan="7" class="muted center">{{ __('لا توجد طلبات تجديد.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="data" hidden>
  <div class="section-toolbar"><div><h2>{{ __('بيانات الشركات') }}</h2><p>{{ __('أحجام البيانات المسجلة لكل شركة — عرض فقط.') }}</p></div></div>
  <article class="owner-card table-card"><div class="table-scroll"><table><thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('الموظفون') }}</th><th>{{ __('المستخدمون') }}</th><th>{{ __('مسيرات الرواتب') }}</th><th>{{ __('الإجازات') }}</th><th>{{ __('الجزاءات') }}</th><th>{{ __('آخر نشاط') }}</th></tr></thead><tbody>@forelse($companies as $company)@php($stats=$dataStats[$company->id] ?? null)<tr><td>{{$company->name}}</td><td>{{format_number($stats['employees'] ?? 0)}}</td><td>{{format_number($stats['users'] ?? 0)}}</td><td>{{format_number($stats['payroll_runs'] ?? 0)}}</td><td>{{format_number($stats['leaves'] ?? 0)}}</td><td>{{format_number($stats['penalties'] ?? 0)}}</td><td>{{!empty($stats['last_activity']) ? \Illuminate\Support\Carbon::parse($stats['last_activity'])->format('d/m/Y H:i') : '—'}}</td></tr>@empty<tr><td colspan="7" class="muted center">{{ __('لا توجد شركات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="notifications" hidden>
  <div class="owner-notification-grid"><article class="owner-card"><header><h2><i class="bi bi-megaphone"></i> {{ __('إرسال إشعار') }}</h2></header><form method="POST" action="{{route('owner.announcements.send')}}" class="form-grid">@csrf<label class="full">{{ __('نطاق الإرسال') }}<select name="audience" id="announcementAudience"><option value="all">{{ __('جميع الشركات') }}</option><option value="company">{{ __('شركة محددة') }}</option></select></label><label class="full" id="announcementCompanyField">{{ __('الشركة') }}<select name="company_id"><option value="">{{ __('اختر الشركة') }}</option>@foreach($companies as $company)<option value="{{$company->id}}">{{$company->name}}</option>@endforeach</select></label><label class="full">{{ __('نص الإشعار') }}<textarea name="message" rows="8" maxlength="2000" required></textarea></label><button class="btn btn-primary full"><i class="bi bi-send"></i> {{ __('إرسال الإشعار') }}</button></form></article>
  <aside class="owner-card help-card"><header><h2><i class="bi bi-lightbulb"></i> {{ __('إرشادات') }}</h2></header><ul><li>{{ __('اكتب رسالة واضحة ومباشرة.') }}</li><li>{{ __('راجع الشركة المستهدفة قبل الإرسال.') }}</li><li>{{ __('سيتم حفظ العملية في سجل العمليات.') }}</li></ul></aside></div>
 </section>

 <section class="owner-panel" data-panel="audit" hidden>
  <div class="section-toolbar"><div><h2>{{ __('سجل عمليات الاشتراكات') }}</h2><p>{{ __('آخر عمليات الإنشاء والتعديل والاعتماد.') }}</p></div><input id="auditSearch" class="search-input" placeholder="{{ __('البحث في السجل...') }}"></div>
  <article class="owner-card table-card"><div class="table-scroll"><table id="auditTable"><thead><tr><th>{{ __('العملية') }}</th><th>{{ __('المستخدم') }}</th><th>{{ __('التفاصيل') }}</th><th>{{ __('التاريخ') }}</th></tr></thead><tbody>@forelse($subscriptionActivity as $activity)<tr><td>{{$activity->action}}</td><td>{{$activity->user?->name??__('النظام')}}</td><td>{{$activity->description}}</td><td>{{$activity->created_at?->format('d/m/Y H:i')}}</td></tr>@empty<tr><td colspan="4" class="muted center">{{ __('لا توجد عمليات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>
</div>

@foreach($plans as $plan)<div class="modal fade" id="editPlan{{$plan->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.plans.update',$plan)}}">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('تعديل الباقة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('اسم الباقة') }}</label><input name="name" value="{{$plan->name}}" class="form-control mb-2" required><label class="form-label">{{ __('حد الموظفين') }}</label><input type="number" name="employee_limit" value="{{$plan->employee_limit}}" class="form-control mb-2" required><label class="form-label">{{ __('حد حسابات HR') }}</label><input type="number" name="hr_user_limit" value="{{$plan->hr_user_limit??5}}" class="form-control mb-2"><label class="form-label">{{ __('السعر') }}</label><input type="number" step=".01" name="price" value="{{$plan->price}}" class="form-control mb-2"><label class="form-label">{{ __('دورة الفوترة') }}</label><select name="billing_period" class="form-select mb-2" required><option value="monthly" @selected($plan->billing_period==='monthly')>{{ __('شهري') }}</option><option value="yearly" @selected($plan->billing_period==='yearly')>{{ __('سنوي') }}</option></select><label class="form-label">{{ __('المزايا') }}</label><textarea name="features" class="form-control mb-2">{{is_array($plan->features)?implode(', ',$plan->features):(string)$plan->features}}</textarea><label class="form-check"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> {{ __('باقة نشطة') }}</label></div><div class="modal-footer"><button class="btn btn-primary">{{ __('حفظ') }}</button></div></form></div></div></div>@endforeach

@foreach($companies as $company) @php($subscription=$latestSubscriptions[$company->id] ?? null)<div class="modal fade" id="companySubscription{{$company->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.companies.subscription.update',$company)}}">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('إدارة اشتراك') }} {{$company->name}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('الباقة') }}</label><select name="plan_id" class="form-select mb-2">@foreach($plans as $plan)<option value="{{$plan->id}}" @selected($subscription?->subscription_plan_id===$plan->id)>{{$plan->name}}</option>@endforeach</select><label class="form-label">{{ __('حالة الاشتراك') }}</label><select name="status" class="form-select mb-2">@foreach($statusLabels as $key=>$label)<option value="{{$key}}" @selected($subscription?->status===$key)>{{$label}}</option>@endforeach</select><label class="form-label">{{ __('تاريخ الانتهاء') }}</label><input type="date" name="ends_at" class="form-control mb-2" value="{{$subscription?->ends_at?->format('Y-m-d')}}"><label class="form-label">{{ __('أيام التجربة') }}</label><input type="number" min="0" name="trial_days" class="form-control" value="{{$subscription?->trial_days??0}}"></div><div class="modal-footer"><button class="btn btn-primary">{{ __('حفظ الاشتراك') }}</button></div></form><form method="POST" action="{{route('owner.companies.status.update',$company)}}" class="p-3">@csrf @method('PUT')<input type="hidden" name="status" value="{{$company->status==='active'?'suspended':'active'}}"><button class="btn btn-sm {{$company->status==='active'?'btn-outline-danger':'btn-success'}}">{{$company->status==='active'?__('إيقاف الشركة'):__('تفعيل الشركة')}}</button></form></div></div></div>@endforeach
@endsection

@push('scripts')
<script>
function openOwnerTab(name){
  document.querySelectorAll('.owner-tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));
  document.querySelectorAll('.owner-panel').forEach(p=>{p.classList.toggle('active',p.dataset.panel===name);p.hidden=p.dataset.panel!==name;});
}
document.querySelectorAll('.owner-tab').forEach(t=>t.addEventListener('click',()=>openOwnerTab(t.dataset.tab)));
document.getElementById('toggleCompanyForm')?.addEventListener('click',()=>{const f=document.getElementById('company-form');f.hidden=!f.hidden;});
document.getElementById('togglePlanForm')?.addEventListener('click',()=>{const f=document.getElementById('plan-form');f.hidden=!f.hidden;});
const companySearch=document.getElementById('companySearch'),companyStatus=document.getElementById('companyStatus');
function filterCompanies(){const q=(companySearch?.value||'').trim(),s=companyStatus?.value||'';document.querySelectorAll('#companiesTable tbody tr').forEach(r=>{const okQ=!q||r.textContent.includes(q),okS=!s||r.querySelector('[data-status]')?.dataset.status===s;r.style.display=(okQ&&okS)?'':'none';});}
companySearch?.addEventListener('input',filterCompanies);companyStatus?.addEventListener('change',filterCompanies);
document.getElementById('auditSearch')?.addEventListener('input',e=>{const q=e.target.value.trim();document.querySelectorAll('#auditTable tbody tr').forEach(r=>{r.style.display=!q||r.textContent.includes(q)?'':'none';});});
const aud=document.getElementById('announcementAudience'),compField=document.getElementById('announcementCompanyField');
function syncAudience(){if(compField)compField.style.display=aud&&aud.value==='company'?'':'none';}
aud?.addEventListener('change',syncAudience);syncAudience();
</script>
<style>
.owner-page{max-width:1800px;margin:auto}
.owner-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.owner-heading h1{font-size:24px;margin:0;font-weight:800}
.owner-heading h1 i{color:#1268f3;margin-inline-end:8px}
.owner-heading p{margin:4px 0 0;color:#71809c;font-size:12px}
.owner-private-badge{display:inline-flex;align-items:center;gap:6px;background:#0f1e33;color:#7fd0ff;border-radius:20px;padding:6px 14px;font-size:11px;font-weight:800;white-space:nowrap}
.owner-tabs{display:flex;gap:6px;background:#fff;border:1px solid #e5ebf3;border-radius:12px;padding:7px;margin-bottom:16px;box-shadow:0 8px 24px #2047780e;overflow:auto}
.owner-tab{flex:1;min-width:max-content;border:1px solid transparent;border-radius:8px;padding:11px 13px;background:transparent;color:#60708c;font:700 12px Cairo,Tahoma,sans-serif;cursor:pointer}
.owner-tab.active,.owner-tab:hover{background:#eaf2ff;border-color:#d6e6ff;color:#1268f3}
.owner-tab b{background:#ffebcf;color:#a96300;border-radius:20px;padding:1px 6px;font-size:10px}
.owner-panel[hidden]{display:none}
.owner-stats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;margin-bottom:15px}
.owner-stat{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e9eff7;border-radius:12px;padding:13px;box-shadow:0 8px 24px #2047780e}
.owner-stat>i{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;font-size:21px;flex-shrink:0}
.owner-stat span,.owner-stat small{display:block;font-size:11px;color:#53627c}
.owner-stat strong{display:block;font-size:21px}
.owner-stat small{font-size:10px;color:#0ca66a}
.owner-stat.green>i{background:#dff8eb;color:#0ca66a}
.owner-stat.blue>i{background:#e2efff;color:#1672f8}
.owner-stat.amber>i{background:#fff2d8;color:#df8b00}
.owner-stat.purple>i{background:#f0e4ff;color:#8b39e8}
.owner-stat.rose>i{background:#ffe4e7;color:#f34d59}
.owner-stat.teal>i{background:#dcf6f3;color:#0d9488}
.owner-stat.slate>i{background:#e8edf5;color:#52627a}
.owner-overview-grid,.owner-notification-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.owner-card{background:#fff;border:1px solid #e7edf5;border-radius:12px;box-shadow:0 8px 24px #2047780e;overflow:hidden;margin-bottom:14px}
.owner-card header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #edf1f6}
.owner-card h2{font-size:14px;margin:0}
.owner-card h2 i{color:#1268f3;margin-inline-end:6px}
.distribution-list{padding:17px}
.distribution-list>div{margin-bottom:13px}
.distribution-list>div>span{font-size:11px;color:#53627c}
.distribution-list b{float:left;font-size:12px}
.distribution-bar{height:7px;border-radius:10px;background:#edf2f7;clear:both;margin-top:6px;overflow:hidden}
.distribution-bar i{display:block;height:100%;background:#1268f3;border-radius:10px}
.overview-list>div{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid #edf1f6}
.overview-list>div:last-child{border-bottom:0}
.overview-list b,.overview-list small{display:block}
.overview-list small{color:#71809c;font-size:10px;margin-top:3px}
.owner-status{display:inline-flex;border-radius:20px;padding:4px 9px;font-size:10px;font-weight:700;white-space:nowrap;background:#edf1f5;color:#60708c}
.owner-status.active{background:#ddf8e9;color:#09975d}
.owner-status.trial{background:#e3f1ff;color:#1670d7}
.owner-status.suspended,.owner-status.expired,.owner-status.rejected{background:#ffe4e6;color:#d92d43}
.owner-status.pending{background:#fff2d8;color:#bd7600}
.owner-status.approved{background:#ddf8e9;color:#09975d}
.owner-card form{padding:16px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}
.form-grid label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;color:#344461}
.form-grid input,.form-grid select,.form-grid textarea{width:100%;border:1px solid #dfe7f1;border-radius:7px;padding:9px 10px;font:inherit;outline:none}
.full{grid-column:1/-1}
.table-card{margin-bottom:14px}
.table-scroll{overflow:auto;padding:0 10px 10px}
.table-scroll table{width:100%;border-collapse:separate;border-spacing:0;white-space:nowrap;font-size:11px}
.table-scroll th{background:#f4f7fb;color:#53627c;padding:10px;text-align:right}
.table-scroll td{padding:9px;border-bottom:1px solid #edf1f6;color:#293a59}
.table-tools{display:flex;gap:8px;padding:10px 12px}
.table-tools input,.table-tools select,.search-input{border:1px solid #dfe7f1;border-radius:8px;padding:9px 12px;font:inherit;outline:none}
.section-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.section-toolbar h2{font-size:18px;margin:0}
.section-toolbar p{font-size:12px;color:#71809c;margin:3px 0}
.help-card ul{margin:0;padding:18px 35px;line-height:2;color:#53627c}
.muted{color:#8795a8}
.center{text-align:center}
.inline-form{display:inline-block;padding:0!important;margin-inline-end:4px}
@media(max-width:1400px){.owner-stats{grid-template-columns:repeat(4,1fr)}}
@media(max-width:1000px){.owner-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:850px){.owner-stats,.owner-overview-grid,.owner-notification-grid{grid-template-columns:1fr}.owner-heading{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){.owner-stats{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}.owner-tab{flex:0 0 auto}}
</style>
@endpush
