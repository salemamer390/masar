@extends('layouts.owner')
@section('title','لوحة تحكم المالك')
@section('content')
@php
 $statusLabels=['trial'=>'تجربة','active'=>'نشط','suspended'=>'موقوف','expired'=>'منتهي'];
 $renewalLabels=['pending'=>'قيد المراجعة','approved'=>'معتمد','rejected'=>'مرفوض'];
 $requestTypeLabels=['renewal'=>'تجديد','upgrade'=>'ترقية','change'=>'تغيير باقة'];
@endphp
<div class="owner-page">
 <div class="owner-heading">
  <div>
   <h1><i class="bi bi-shield-lock-fill"></i> لوحة تحكم المالك</h1>
   <p>إدارة الشركات والباقات والاشتراكات والبيانات — صفحة خاصة، لا تظهر في القوائم ولا يفتحها سوى حسابك.</p>
  </div>
  <span class="owner-private-badge"><i class="bi bi-lock-fill"></i> وصول خاص</span>
 </div>
 <div class="owner-tabs" role="tablist">
  <button class="owner-tab active" data-tab="overview"><i class="bi bi-grid-1x2"></i> نظرة عامة</button>
  <button class="owner-tab" data-tab="companies"><i class="bi bi-buildings"></i> الشركات <b>{{$companies->count()}}</b></button>
  <button class="owner-tab" data-tab="plans"><i class="bi bi-layers"></i> الباقات <b>{{$plans->count()}}</b></button>
  <button class="owner-tab" data-tab="requests"><i class="bi bi-arrow-repeat"></i> طلبات التجديد <b>{{$pendingRenewals}}</b></button>
  <button class="owner-tab" data-tab="data"><i class="bi bi-database"></i> البيانات</button>
  <button class="owner-tab" data-tab="notifications"><i class="bi bi-megaphone"></i> الإشعارات</button>
  <button class="owner-tab" data-tab="audit"><i class="bi bi-clock-history"></i> سجل العمليات</button>
 </div>

 <section class="owner-panel active" data-panel="overview">
  <div class="owner-stats">
   <article class="owner-stat green"><i class="bi bi-buildings-fill"></i><div><span>الشركات النشطة</span><strong>{{format_number($activeCompanies)}}</strong><small>من إجمالي {{format_number($companies->count())}}</small></div></article>
   <article class="owner-stat blue"><i class="bi bi-layers-fill"></i><div><span>الاشتراكات النشطة</span><strong>{{format_number($activeSubscriptions)}}</strong><small>تجربة أو نشط</small></div></article>
   <article class="owner-stat amber"><i class="bi bi-hourglass-split"></i><div><span>تنتهي خلال 30 يومًا</span><strong>{{format_number($expiringSubscriptions)}}</strong><small>تحتاج متابعة</small></div></article>
   <article class="owner-stat purple"><i class="bi bi-cash-stack"></i><div><span>الإيرادات المعتمدة</span><strong>{{number_format($approvedRevenue,2)}}</strong><small>ج.م</small></div></article>
   <article class="owner-stat rose"><i class="bi bi-inbox"></i><div><span>طلبات قيد المراجعة</span><strong>{{format_number($pendingRenewals)}}</strong><small>طلبات التجديد</small></div></article>
   <article class="owner-stat teal"><i class="bi bi-people-fill"></i><div><span>إجمالي الموظفين</span><strong>{{format_number($totalEmployees)}}</strong><small>في كل الشركات</small></div></article>
   <article class="owner-stat slate"><i class="bi bi-person-badge-fill"></i><div><span>حسابات المستخدمين</span><strong>{{format_number($totalUsers)}}</strong><small>حسابات الشركات</small></div></article>
  </div>
  <div class="owner-overview-grid">
   <article class="owner-card"><header><h2><i class="bi bi-pie-chart"></i> توزيع الشركات حسب الباقة</h2></header><div class="distribution-list">@forelse($companiesByPlan as $planName=>$count)<div><span>{{$planName}}</span><b>{{format_number($count)}}</b><div class="distribution-bar"><i style="width:{{$activeCompanies?min(100,($count/$activeCompanies)*100):0}}%"></i></div></div>@empty<p class="muted p-3">لا توجد اشتراكات نشطة.</p>@endforelse</div></article>
   <article class="owner-card"><header><h2><i class="bi bi-arrow-repeat"></i> أحدث طلبات التجديد</h2><button class="btn btn-light btn-sm" type="button" onclick="openOwnerTab('requests')">عرض الكل</button></header><div class="overview-list">@forelse($renewalRequests->take(5) as $renewal)<div><div><b>{{$renewal->company?->name}}</b><small>{{$renewal->plan?->name}} · {{number_format($renewal->amount,2)}} ج.م</small></div><span class="owner-status {{$renewal->status}}">{{$renewalLabels[$renewal->status]??$renewal->status}}</span></div>@empty<p class="muted p-3">لا توجد طلبات تجديد.</p>@endforelse</div></article>
  </div>
  <article class="owner-card table-card"><header><h2><i class="bi bi-activity"></i> آخر نشاط</h2><button class="btn btn-light btn-sm" type="button" onclick="openOwnerTab('audit')">سجل العمليات</button></header><div class="table-scroll"><table><thead><tr><th>العملية</th><th>المستخدم</th><th>التفاصيل</th><th>التاريخ</th></tr></thead><tbody>@forelse($subscriptionActivity->take(6) as $activity)<tr><td>{{$activity->action}}</td><td>{{$activity->user?->name??'النظام'}}</td><td>{{$activity->description}}</td><td>{{$activity->created_at?->format('d/m/Y H:i')}}</td></tr>@empty<tr><td colspan="4" class="muted center">لا توجد عمليات مسجلة.</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="companies" hidden>
  <div class="section-toolbar"><div><h2>الشركات المشتركة</h2><p>إنشاء الشركات وإدارة حالتها واشتراكاتها.</p></div><button type="button" class="btn btn-primary" id="toggleCompanyForm"><i class="bi bi-plus-lg"></i> إضافة شركة</button></div>
  <article class="owner-card" id="company-form" hidden><header><h2><i class="bi bi-building-add"></i> إضافة شركة واشتراك</h2></header><form method="POST" action="{{route('owner.companies.store')}}" class="form-grid">@csrf<label>اسم الشركة<input name="name" required></label><label>البريد الإلكتروني<input type="email" name="email"></label><label>الهاتف<input name="phone"></label><label>الباقة<select name="plan_id" required>@foreach($plans as $plan)<option value="{{$plan->id}}">{{$plan->name}}</option>@endforeach</select></label><label>مدة التجربة بالأيام<input type="number" name="trial_days" value="0" min="0"></label><label>اسم مدير الشركة<input name="admin_name" required></label><label>بريد المدير<input type="email" name="admin_email" required></label><label>كلمة مرور المدير<input type="password" name="admin_password" minlength="6" required></label><button class="btn btn-primary full">إنشاء الشركة والحساب</button></form></article>
  <article class="owner-card table-card"><header><h2><i class="bi bi-buildings"></i> قائمة الشركات</h2></header><div class="table-tools"><input id="companySearch" placeholder="البحث في الشركات..."><select id="companyStatus"><option value="">كل الحالات</option><option value="active">نشطة</option><option value="suspended">موقوفة</option></select></div><div class="table-scroll"><table id="companiesTable"><thead><tr><th>الشركة</th><th>حالة الشركة</th><th>الاشتراك</th><th>الباقة</th><th>ينتهي في</th><th>الموظفون</th><th>المستخدمون</th><th></th></tr></thead><tbody>@forelse($companies as $company)@php($subscription=$latestSubscriptions[$company->id] ?? null)<tr><td>{{$company->name}}</td><td data-status="{{$company->status}}"><span class="owner-status {{$company->status==='active'?'active':'suspended'}}">{{$company->status==='active'?'نشطة':'موقوفة'}}</span></td><td><span class="owner-status {{$subscription?->status??'expired'}}">{{$statusLabels[$subscription?->status]??'بدون اشتراك'}}</span></td><td>{{$subscription?->plan?->name??'—'}}</td><td>{{$subscription?->ends_at?->format('d/m/Y')??'بدون تاريخ انتهاء'}}</td><td>{{format_number($dataStats[$company->id]['employees'] ?? 0)}}</td><td>{{format_number($dataStats[$company->id]['users'] ?? 0)}}</td><td><button class="btn btn-outline btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#companySubscription{{$company->id}}">إدارة</button></td></tr>@empty<tr><td colspan="8" class="muted center">لا توجد شركات مسجلة.</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="plans" hidden>
  <div class="section-toolbar"><div><h2>الباقات</h2><p>إضافة وتعديل الباقات وحدود الاستخدام.</p></div><button type="button" class="btn btn-primary" id="togglePlanForm"><i class="bi bi-plus-lg"></i> إضافة باقة</button></div>
  <article class="owner-card" id="plan-form" hidden><header><h2><i class="bi bi-layers"></i> إضافة باقة</h2></header><form method="POST" action="{{route('owner.plans.store')}}" class="form-grid">@csrf<label>اسم الباقة<input name="name" required></label><label>حد الموظفين<input type="number" name="employee_limit" min="1" required></label><label>حد حسابات الموارد البشرية<input type="number" name="hr_user_limit" min="0" value="5"></label><label>السعر<input type="number" step="0.01" name="price" min="0"></label><label>دورة الفوترة<select name="billing_period"><option value="monthly">شهري</option><option value="yearly">سنوي</option></select></label><label class="full">المزايا<textarea name="features" rows="2"></textarea></label><button class="btn btn-primary full">إضافة الباقة</button></form></article>
  <article class="owner-card table-card"><header><h2>الباقات الحالية</h2></header><div class="table-scroll"><table><thead><tr><th>الباقة</th><th>حد الموظفين</th><th>حد HR</th><th>السعر</th><th>الدورة</th><th>الحالة</th><th></th></tr></thead><tbody>@forelse($plans as $plan)<tr><td>{{$plan->name}}</td><td>{{number_format($plan->employee_limit)}}</td><td>{{number_format($plan->hr_user_limit??5)}}</td><td>{{number_format($plan->price,2)}} ج.م</td><td>{{$plan->billing_period==='yearly'?'سنوي':'شهري'}}</td><td><span class="owner-status {{$plan->is_active?'active':'suspended'}}">{{$plan->is_active?'نشطة':'موقوفة'}}</span></td><td><button class="btn btn-outline btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#editPlan{{$plan->id}}">تعديل</button></td></tr>@empty<tr><td colspan="7" class="muted center">لا توجد باقات.</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="requests" hidden>
  <div class="section-toolbar"><div><h2>طلبات التجديد والترقية</h2><p>مراجعة الطلبات واتخاذ إجراء عليها.</p></div></div>
  <article class="owner-card table-card"><div class="table-scroll"><table><thead><tr><th>الشركة</th><th>النوع</th><th>الباقة المطلوبة</th><th>المبلغ</th><th>مقدم الطلب</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>@forelse($renewalRequests as $renewal)<tr><td>{{$renewal->company?->name}}</td><td>{{$requestTypeLabels[$renewal->request_type]??$renewal->request_type}}</td><td>{{$renewal->plan?->name}}</td><td>{{number_format($renewal->amount,2)}} ج.م</td><td>{{$renewal->requester?->name??'—'}}</td><td><span class="owner-status {{$renewal->status}}">{{$renewalLabels[$renewal->status]??$renewal->status}}</span></td><td>@if($renewal->status==='pending')<form method="POST" action="{{route('owner.renewals.update',$renewal)}}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="status" value="approved"><button class="btn btn-success btn-sm">اعتماد</button></form><form method="POST" action="{{route('owner.renewals.update',$renewal)}}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="status" value="rejected"><button class="btn btn-outline-danger btn-sm">رفض</button></form>@else—@endif</td></tr>@empty<tr><td colspan="7" class="muted center">لا توجد طلبات تجديد.</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="data" hidden>
  <div class="section-toolbar"><div><h2>بيانات الشركات</h2><p>أحجام البيانات المسجلة لكل شركة — عرض فقط.</p></div></div>
  <article class="owner-card table-card"><div class="table-scroll"><table><thead><tr><th>الشركة</th><th>الموظفون</th><th>المستخدمون</th><th>مسيرات الرواتب</th><th>الإجازات</th><th>الجزاءات</th><th>آخر نشاط</th></tr></thead><tbody>@forelse($companies as $company)@php($stats=$dataStats[$company->id] ?? null)<tr><td>{{$company->name}}</td><td>{{format_number($stats['employees'] ?? 0)}}</td><td>{{format_number($stats['users'] ?? 0)}}</td><td>{{format_number($stats['payroll_runs'] ?? 0)}}</td><td>{{format_number($stats['leaves'] ?? 0)}}</td><td>{{format_number($stats['penalties'] ?? 0)}}</td><td>{{!empty($stats['last_activity']) ? \Illuminate\Support\Carbon::parse($stats['last_activity'])->format('d/m/Y H:i') : '—'}}</td></tr>@empty<tr><td colspan="7" class="muted center">لا توجد شركات مسجلة.</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="notifications" hidden>
  <div class="owner-notification-grid"><article class="owner-card"><header><h2><i class="bi bi-megaphone"></i> إرسال إشعار</h2></header><form method="POST" action="{{route('owner.announcements.send')}}" class="form-grid">@csrf<label class="full">نطاق الإرسال<select name="audience" id="announcementAudience"><option value="all">جميع الشركات</option><option value="company">شركة محددة</option></select></label><label class="full" id="announcementCompanyField">الشركة<select name="company_id"><option value="">اختر الشركة</option>@foreach($companies as $company)<option value="{{$company->id}}">{{$company->name}}</option>@endforeach</select></label><label class="full">نص الإشعار<textarea name="message" rows="8" maxlength="2000" required></textarea></label><button class="btn btn-primary full"><i class="bi bi-send"></i> إرسال الإشعار</button></form></article>
  <aside class="owner-card help-card"><header><h2><i class="bi bi-lightbulb"></i> إرشادات</h2></header><ul><li>اكتب رسالة واضحة ومباشرة.</li><li>راجع الشركة المستهدفة قبل الإرسال.</li><li>سيتم حفظ العملية في سجل العمليات.</li></ul></aside></div>
 </section>

 <section class="owner-panel" data-panel="audit" hidden>
  <div class="section-toolbar"><div><h2>سجل عمليات الاشتراكات</h2><p>آخر عمليات الإنشاء والتعديل والاعتماد.</p></div><input id="auditSearch" class="search-input" placeholder="البحث في السجل..."></div>
  <article class="owner-card table-card"><div class="table-scroll"><table id="auditTable"><thead><tr><th>العملية</th><th>المستخدم</th><th>التفاصيل</th><th>التاريخ</th></tr></thead><tbody>@forelse($subscriptionActivity as $activity)<tr><td>{{$activity->action}}</td><td>{{$activity->user?->name??'النظام'}}</td><td>{{$activity->description}}</td><td>{{$activity->created_at?->format('d/m/Y H:i')}}</td></tr>@empty<tr><td colspan="4" class="muted center">لا توجد عمليات مسجلة.</td></tr>@endforelse</tbody></table></div></article>
 </section>
</div>

@foreach($plans as $plan)<div class="modal fade" id="editPlan{{$plan->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.plans.update',$plan)}}">@csrf @method('PUT')<div class="modal-header"><h5>تعديل الباقة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">اسم الباقة</label><input name="name" value="{{$plan->name}}" class="form-control mb-2" required><label class="form-label">حد الموظفين</label><input type="number" name="employee_limit" value="{{$plan->employee_limit}}" class="form-control mb-2" required><label class="form-label">حد حسابات HR</label><input type="number" name="hr_user_limit" value="{{$plan->hr_user_limit??5}}" class="form-control mb-2"><label class="form-label">السعر</label><input type="number" step=".01" name="price" value="{{$plan->price}}" class="form-control mb-2"><label class="form-label">دورة الفوترة</label><select name="billing_period" class="form-select mb-2" required><option value="monthly" @selected($plan->billing_period==='monthly')>شهري</option><option value="yearly" @selected($plan->billing_period==='yearly')>سنوي</option></select><label class="form-label">المزايا</label><textarea name="features" class="form-control mb-2">{{is_array($plan->features)?implode(', ',$plan->features):(string)$plan->features}}</textarea><label class="form-check"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> باقة نشطة</label></div><div class="modal-footer"><button class="btn btn-primary">حفظ</button></div></form></div></div></div>@endforeach

@foreach($companies as $company) @php($subscription=$latestSubscriptions[$company->id] ?? null)<div class="modal fade" id="companySubscription{{$company->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.companies.subscription.update',$company)}}">@csrf @method('PUT')<div class="modal-header"><h5>إدارة اشتراك {{$company->name}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الباقة</label><select name="plan_id" class="form-select mb-2">@foreach($plans as $plan)<option value="{{$plan->id}}" @selected($subscription?->subscription_plan_id===$plan->id)>{{$plan->name}}</option>@endforeach</select><label class="form-label">حالة الاشتراك</label><select name="status" class="form-select mb-2">@foreach($statusLabels as $key=>$label)<option value="{{$key}}" @selected($subscription?->status===$key)>{{$label}}</option>@endforeach</select><label class="form-label">تاريخ الانتهاء</label><input type="date" name="ends_at" class="form-control mb-2" value="{{$subscription?->ends_at?->format('Y-m-d')}}"><label class="form-label">أيام التجربة</label><input type="number" min="0" name="trial_days" class="form-control" value="{{$subscription?->trial_days??0}}"></div><div class="modal-footer"><button class="btn btn-primary">حفظ الاشتراك</button></div></form><form method="POST" action="{{route('owner.companies.status.update',$company)}}" class="p-3">@csrf @method('PUT')<input type="hidden" name="status" value="{{$company->status==='active'?'suspended':'active'}}"><button class="btn btn-sm {{$company->status==='active'?'btn-outline-danger':'btn-success'}}">{{$company->status==='active'?'إيقاف الشركة':'تفعيل الشركة'}}</button></form></div></div></div>@endforeach
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
