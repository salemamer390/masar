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
  <button class="owner-tab" data-tab="database-updates"><i class="bi bi-database-gear"></i> {{ __('تحديثات قاعدة البيانات') }} @if(count($databaseUpdateStatus['pending'] ?? []))<b>{{count($databaseUpdateStatus['pending'])}}</b>@endif</button>
  <button class="owner-tab" data-tab="payroll-rules"><i class="bi bi-shield-check"></i> {{ __('إعدادات الرواتب') }}</button>
  <button class="owner-tab" data-tab="notifications"><i class="bi bi-megaphone"></i> {{ __('الإشعارات') }}</button>
  <button class="owner-tab" data-tab="audit"><i class="bi bi-clock-history"></i> {{ __('سجل العمليات') }}</button>
  <a class="owner-tab" href="{{ route('owner.tour.index') }}"><i class="bi bi-signpost-2"></i> {{ __('الجولة التعريفية') }}</a>
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
  <article class="owner-card table-card"><header><h2><i class="bi bi-buildings"></i> {{ __('قائمة الشركات') }}</h2></header><div class="table-tools"><input id="companySearch" placeholder="{{ __('البحث في الشركات...') }}"><select id="companyStatus"><option value="">{{ __('كل الحالات') }}</option><option value="active">{{ __('نشطة') }}</option><option value="suspended">{{ __('موقوفة') }}</option></select></div><div class="table-scroll"><table id="companiesTable"><thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('المدير') }}</th><th>{{ __('بريد دخول المدير') }}</th><th>{{ __('حالة الشركة') }}</th><th>{{ __('الاشتراك') }}</th><th>{{ __('الباقة') }}</th><th>{{ __('ينتهي في') }}</th><th>{{ __('الموظفون') }}</th><th>{{ __('المستخدمون') }}</th><th></th></tr></thead><tbody>@forelse($companies as $company)@php $subscription=$latestSubscriptions[$company->id] ?? null; $managers=collect($companyDetails[$company->id]['admins'] ?? [])->values(); @endphp<tr><td>{{$company->name}}</td><td class="company-manager">@forelse($managers as $m){{$m->name}}@if(!$loop->last)<br>@endif @empty—@endforelse</td><td class="company-manager-email" dir="ltr">@forelse($managers as $m){{$m->email}}@if(!$loop->last)<br>@endif @empty—@endforelse</td><td data-status="{{$company->status}}"><span class="owner-status {{$company->status==='active'?'active':'suspended'}}">{{$company->status==='active'?__('نشطة'):__('موقوفة')}}</span></td><td><span class="owner-status {{$subscription?->status??'expired'}}">{{$statusLabels[$subscription?->status]??__('بدون اشتراك')}}</span></td><td>{{$subscription?->plan?->name??'—'}}</td><td>{{$subscription?->ends_at?->format('d/m/Y')??__('بدون تاريخ انتهاء')}}</td><td>{{format_number($dataStats[$company->id]['employees'] ?? 0)}}</td><td>{{format_number($dataStats[$company->id]['users'] ?? 0)}}</td><td class="company-actions"><div class="company-action-group"><button class="co-btn co-btn-details" type="button" aria-label="{{ __('تفاصيل') }}" title="{{ __('تفاصيل') }}" data-bs-toggle="modal" data-bs-target="#companyDetails{{$company->id}}"><i class="bi bi-eye" aria-hidden="true"></i><span class="co-label">{{ __('تفاصيل') }}</span></button><button class="co-btn co-btn-manage" type="button" aria-label="{{ __('إدارة') }}" title="{{ __('إدارة') }}" data-bs-toggle="modal" data-bs-target="#companySubscription{{$company->id}}"><i class="bi bi-sliders" aria-hidden="true"></i><span class="co-label">{{ __('إدارة') }}</span></button><button class="co-btn co-btn-reset" type="button" aria-label="{{ __('إعادة تعيين كلمة المرور') }}" title="{{ __('إعادة تعيين كلمة المرور') }}" data-bs-toggle="modal" data-bs-target="#companyPassword{{$company->id}}"><i class="bi bi-key" aria-hidden="true"></i><span class="co-label">{{ __('تعيين الباسورد') }}</span></button><button class="co-btn co-btn-delete company-delete-btn" type="button" aria-label="{{ __('حذف الشركة') }}" title="{{ __('حذف الشركة') }}" data-bs-toggle="modal" data-bs-target="#companyDelete{{$company->id}}" data-preview-url="{{route('owner.companies.erasure.preview',$company)}}"><i class="bi bi-trash3" aria-hidden="true"></i><span class="co-label">{{ __('حذف') }}</span></button></div></td></tr>@empty<tr><td colspan="10" class="muted center">{{ __('لا توجد شركات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
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
  <article class="owner-card table-card"><div class="table-scroll"><table><thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('الموظفون') }}</th><th>{{ __('المستخدمون') }}</th><th>{{ __('مسيرات الرواتب') }}</th><th>{{ __('الإجازات') }}</th><th>{{ __('الجزاءات') }}</th><th>{{ __('آخر نشاط') }}</th></tr></thead><tbody>@forelse($companies as $company)@php $stats=$dataStats[$company->id] ?? null; @endphp<tr><td>{{$company->name}}</td><td>{{format_number($stats['employees'] ?? 0)}}</td><td>{{format_number($stats['users'] ?? 0)}}</td><td>{{format_number($stats['payroll_runs'] ?? 0)}}</td><td>{{format_number($stats['leaves'] ?? 0)}}</td><td>{{format_number($stats['penalties'] ?? 0)}}</td><td>{{!empty($stats['last_activity']) ? \Illuminate\Support\Carbon::parse($stats['last_activity'])->format('d/m/Y H:i') : '—'}}</td></tr>@empty<tr><td colspan="7" class="muted center">{{ __('لا توجد شركات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>

 <section class="owner-panel" data-panel="database-updates" hidden>
  @php
   $databasePending=$databaseUpdateStatus['pending'] ?? [];
   $blockedDatabaseUpdates=$databaseUpdateStatus['blocked'] ?? [];
   $nextDatabaseUpdate=$databaseUpdateStatus['next'] ?? null;
   $databaseAttempt=$databaseUpdateStatus['attempt'] ?? null;
   $blockingDatabaseUpdate=$databaseUpdateStatus['blocking'] ?? null;
   $databaseReadinessChecks=$databaseUpdateStatus['checks'] ?? [];
   $readinessIcons=['ok'=>'bi-check-circle-fill','warning'=>'bi-exclamation-triangle-fill','failed'=>'bi-x-octagon-fill'];
   $readinessLabels=['ok'=>__('جاهز'),'warning'=>__('انتبه'),'failed'=>__('متوقف')];
   $blockedReasonLabels=[
    'unreviewed'=>__('غير موجودة في قائمة المراجعة'),
    'changed'=>__('تغير محتواها بعد المراجعة'),
    'unreadable'=>__('تعذر قراءة ملفها للتحقق'),
    'outside_path'=>__('ملف غير مباشر داخل مجلد migrations الرسمي'),
   ];
  @endphp
  <div class="section-toolbar"><div><h2>{{ __('تحديثات قاعدة البيانات') }}</h2><p>{{ __('تنفيذ تحديث واحد مراجع ببصمة ملفه فقط في كل مرة بعد تأكيد النسخة الاحتياطية.') }}</p></div></div>
  <div class="owner-update-grid">
   <article class="owner-card update-status-card">
    <header><h2><i class="bi bi-database-gear"></i> {{ __('حالة التحديثات') }}</h2>@if(!($databaseUpdateStatus['available'] ?? false))<span class="owner-status rejected">{{ __('غير متاحة') }}</span>@elseif($databaseAttempt)<span class="owner-status rejected">{{ __('متوقفة للمراجعة') }}</span>@elseif($blockingDatabaseUpdate)<span class="owner-status rejected">{{ __('متوقفة بسبب تحديث سابق') }}</span>@elseif(count($databasePending))<span class="owner-status pending">{{ __('يوجد تحديثات مصرح بها') }}</span>@elseif(count($blockedDatabaseUpdates))<span class="owner-status rejected">{{ __('يوجد تحديثات محظورة') }}</span>@else<span class="owner-status approved">{{ __('محدثة') }}</span>@endif</header>
     @if(session('database_update_audit_warning'))
      <div class="update-unavailable"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>{{ __('تنبيه سجل العمليات') }}</strong><p>{{session('database_update_audit_warning')}}</p></div></div>
     @endif
    @if(!($databaseUpdateStatus['available'] ?? false))
     <div class="update-unavailable"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>{{ __('لا يمكن تنفيذ التحديثات من هذه الصفحة الآن') }}</strong><p>{{$databaseUpdateStatus['error']}}</p></div></div>
    @else
     <div class="update-summary">
      <div><span>{{ __('تحديثات مصرح بها') }}</span><strong>{{format_number(count($databasePending))}}</strong></div>
      <div><span>{{ __('تحديثات محظورة') }}</span><strong>{{format_number(count($blockedDatabaseUpdates))}}</strong></div>
      <div><span>{{ __('آخر دفعة') }}</span><strong>{{$databaseUpdateStatus['last_batch'] ? format_number($databaseUpdateStatus['last_batch']) : '—'}}</strong></div>
     </div>
     @if($databaseAttempt)
      <div class="update-unavailable"><i class="bi bi-exclamation-octagon-fill"></i><div><strong>{{ __('التنفيذ متوقف للمراجعة اليدوية') }}</strong><p>{{ __('محاولة سابقة للتحديث :migration لم تكتمل أو تحتاج مراجعة. راجع قاعدة البيانات وسجل migrations يدويًا قبل السماح بأي محاولة أخرى.', ['migration' => $databaseAttempt['migration'] ?? __('غير معروف')]) }}</p><ol class="recovery-steps"><li>{{ __('أوقف استخدام النظام مؤقتًا حتى لا تضاف بيانات أثناء المراجعة.') }}</li><li>{{ __('راجع قاعدة البيانات وجدول migrations وحدد هل التحديث اكتمل فعلًا أم توقف في المنتصف.') }}</li><li>{{ __('إذا احتجت الاستعادة، استخدم آخر Export كامل محفوظ من phpMyAdmin؛ هذا سيرجع كل الشركات إلى وقت النسخة.') }}</li><li>{{ __('بعد التأكد من الحالة، احذف ملف storage/framework/owner-database-updates-attempt.json يدويًا من الخادم. لا يوجد زر تصفير داخل الصفحة.') }}</li><li>{{ __('عند رفع ملفات الموقع لا تحذف أو تستبدل مجلد storage/framework حتى لا تضيع علامة الإيقاف قبل المراجعة.') }}</li></ol></div></div>
     @elseif($blockingDatabaseUpdate)
      <div class="update-unavailable"><i class="bi bi-sign-stop"></i><div><strong>{{ __('يوجد تحديث سابق يمنع المتابعة') }}</strong><p>{{ __('التحديث :migration غير مصرح به أو يحتاج مراجعة، ولن يتم تجاوزه لتنفيذ تحديث لاحق.', ['migration' => $blockingDatabaseUpdate['name']]) }}</p></div></div>
     @elseif($nextDatabaseUpdate)
      <div class="next-update"><span>{{ __('التحديث التالي الذي سيتم تنفيذه') }}</span><code>{{$nextDatabaseUpdate['name']}}</code></div>
      <form method="POST" action="{{route('owner.database-updates.run')}}" class="database-update-form">
       @csrf
       <label>{{ __('كلمة مرور حساب المالك الحالية') }}<input type="password" name="password" autocomplete="current-password" required>@error('password')<small class="field-error">{{$message}}</small>@enderror</label>
       <label class="check-row"><input type="checkbox" name="confirm_backup" value="1" @checked(old('confirm_backup'))><span>{{ __('أؤكد أنني سحبت Export كامل لقاعدة البيانات من phpMyAdmin وحفظته في مكان آمن.') }}</span></label>
       @error('confirm_backup')<small class="field-error">{{$message}}</small>@enderror
       <label class="check-row"><input type="checkbox" name="confirm_partial_risk" value="1" @checked(old('confirm_partial_risk'))><span>{{ __('أفهم أن تحديث MySQL قد يتوقف وهو مكتمل جزئيًا ويحتاج مراجعة يدوية.') }}</span></label>
       @error('confirm_partial_risk')<small class="field-error">{{$message}}</small>@enderror
       @error('database_update')<div class="database-update-error"><i class="bi bi-x-octagon-fill"></i> {{$message}}</div>@enderror
       <button class="btn btn-primary" type="submit"><i class="bi bi-play-circle"></i> {{ __('تنفيذ التحديث التالي فقط') }}</button>
       <small class="muted">{{ __('كل عملية تنفذ أول تحديث مراجع ومصرح به فقط، ولا تنفذ أي أمر أو SQL مكتوب يدويًا.') }}</small>
      </form>
     @elseif(count($blockedDatabaseUpdates))
      <div class="update-unavailable"><i class="bi bi-slash-circle"></i><div><strong>{{ __('لا توجد تحديثات مصرح بها للتنفيذ') }}</strong><p>{{ __('التحديثات المعروضة أدناه غير مراجعة أو تغير محتواها بعد المراجعة، لذلك تبقى محظورة افتراضيًا.') }}</p></div></div>
     @else
      <div class="update-complete"><i class="bi bi-check-circle-fill"></i><div><strong>{{ __('لا توجد تحديثات معلقة') }}</strong><p>{{ __('ملفات migrations وسجل قاعدة البيانات متطابقان حاليًا.') }}</p></div></div>
     @endif
     @if(count($databasePending))
      <div class="pending-list-wrap"><h3>{{ __('التحديثات المصرح بتنفيذها') }}</h3><ol class="pending-migration-list">@foreach(array_slice($databasePending,0,10) as $migration)<li><code>{{$migration['name']}}</code></li>@endforeach</ol>@if(count($databasePending)>10)<p class="muted">{{ __('ويوجد :count تحديثات أخرى بعدها.', ['count' => format_number(count($databasePending)-10)]) }}</p>@endif</div>
     @endif
     @if(count($blockedDatabaseUpdates))
      <div class="pending-list-wrap blocked-list-wrap"><h3>{{ __('تحديثات محظورة من التنفيذ') }}</h3><ol class="pending-migration-list blocked-migration-list">@foreach(array_slice($blockedDatabaseUpdates,0,10) as $migration)<li><code>{{$migration['name']}}</code><small class="blocked-reason">{{$blockedReasonLabels[$migration['reason']] ?? $migration['reason']}}</small></li>@endforeach</ol>@if(count($blockedDatabaseUpdates)>10)<p class="muted">{{ __('ويوجد :count تحديثات محظورة أخرى.', ['count' => format_number(count($blockedDatabaseUpdates)-10)]) }}</p>@endif</div>
     @endif
    @endif
   </article>
   <aside class="owner-card help-card">
    <header><h2><i class="bi bi-clipboard2-check"></i> {{ __('فحص الجاهزية') }}</h2></header>
    @if(count($databaseReadinessChecks))
     <ul class="readiness-list">@foreach($databaseReadinessChecks as $check)<li class="readiness-{{$check['state']}}"><i class="bi {{$readinessIcons[$check['state']] ?? 'bi-info-circle-fill'}}"></i><div><strong>{{$check['label']}}</strong><small>{{$check['detail']}}</small></div><span>{{$readinessLabels[$check['state']] ?? $check['state']}}</span></li>@endforeach</ul>
    @else
     <p class="readiness-empty">{{ __('لم تتوفر فحوصات الجاهزية في هذه الحالة.') }}</p>
    @endif
    <div class="readiness-note"><i class="bi bi-hdd-network"></i> {{ __('احتفظ بمجلد storage/framework أثناء رفع ملفات الموقع؛ حذفه قد يخفي علامة إيقاف محاولة سابقة.') }}</div>
    <h3 class="help-subtitle"><i class="bi bi-shield-exclamation"></i> {{ __('قبل التنفيذ') }}</h3>
    <ul><li>{{ __('احفظ Export كامل من phpMyAdmin في مكان آمن قبل التحديث؛ الصفحة لا تنشئ النسخة ولا تتحقق منها.') }}</li><li>{{ __('نفذ تحديثًا واحدًا صغيرًا ومجربًا ثم راجع الموقع قبل تنفيذ التالي.') }}</li><li>{{ __('لا تنفذ التحديث أثناء ذروة استخدام الموظفين.') }}</li><li>{{ __('لا يعمل أي تحديث غير موجود في قائمة المراجعة أو تغير محتواه بعد المراجعة.') }}</li><li>{{ __('إذا فشل تحديث أو انقطع تنفيذه تتوقف الصفحة حتى مراجعة قاعدة البيانات يدويًا.') }}</li><li>{{ __('هذه الصفحة لا تصلح إعداد قاعدة بيانات جديدة ولا تنفذ أوامر عشوائية أو Backup أو Restore تلقائيًا.') }}</li></ul>
   </aside>
  </div>
 </section>

 <section class="owner-panel owner-rules-panel" data-panel="payroll-rules" hidden>
  @foreach(['01','03'] as $psCss)<link rel="stylesheet" href="{{ asset('css/pages/payroll-settings-'.$psCss.'.css') }}?v={{ @filemtime(public_path('css/pages/payroll-settings-'.$psCss.'.css')) }}">@endforeach
  <div class="section-toolbar"><div><h2>{{ __('إعدادات الرواتب') }}</h2><p>{{ __('قيم عامة تسري على كل الشركات. أي تعديل هنا يعيد حساب هياكل الرواتب في كل الشركات، والمسيرات المعتمدة أو المصروفة لا تتغير. الشركات تراها للعرض فقط.') }}</p></div></div>
  @error('payroll_rules')<div class="alert alert-danger">{{ $message }}</div>@enderror
  <nav class="settings-tabs" aria-label="{{ __('أقسام إعدادات الرواتب') }}"><button type="button" class="settings-tab active" data-owner-rules-tab="ownerRulesPane"><i class="bi bi-cash-stack"></i> {{ __('قواعد الرواتب') }}</button><button type="button" class="settings-tab" data-owner-rules-tab="ownerTaxPane"><i class="bi bi-percent"></i> {{ __('شرائح الضرائب') }}</button></nav>
  <div id="ownerRulesPane" class="settings-tab-pane">
  <form method="POST" action="{{ route('owner.payroll-rules.settings') }}" onsubmit="return confirm('{{ __('سيتم تطبيق القيم على كل الشركات وإعادة حساب الرواتب. متابعة؟') }}')">@csrf @method('PUT')
   <div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> {{ __('التعديلات الجديدة تسري من التاريخ المحدد') }}</strong><span>{{ __('حدد التاريخ الذي تبدأ منه القواعد الجديدة.') }}</span></div><div class="settings-effective-field"><label class="form-label">{{ __('تاريخ السريان') }}</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div></div>
   @php
   $ownerCards=[
    ['title'=>__('التأمينات الاجتماعية'),'subtitle'=>__('نسب الاشتراكات وحدود الأجر التأميني'),'fields'=>[
     ['key'=>'insurance.employee_insurance_rate','label'=>__('تأمين الموظف'),'unit'=>__('٪')],
     ['key'=>'insurance.employer_insurance_rate','label'=>__('تأمين صاحب العمل'),'unit'=>__('٪')],
     ['key'=>'insurance.martyrs_fund_rate','label'=>__('صندوق أسر الشهداء'),'unit'=>__('٪')],
     ['key'=>'insurance.insurance_minimum','label'=>__('الحد الأدنى للأجر التأميني'),'unit'=>__('ج.م')],
     ['key'=>'insurance.insurance_maximum','label'=>__('الحد الأقصى للأجر التأميني'),'unit'=>__('ج.م')]]],
    ['title'=>__('الضرائب'),'subtitle'=>__('الإعفاءات والخصومات الضريبية'),'fields'=>[
     ['key'=>'tax.personal_exemption','label'=>__('الإعفاء الشخصي الشهري'),'unit'=>__('ج.م')],
     ['key'=>'tax.tax_deductible_contributions','label'=>__('اشتراكات قابلة للخصم ضريبيًا'),'unit'=>__('ج.م'),'hint'=>__('سنويًا')],
     ['key'=>'tax.profit_share_exempt_percent','label'=>__('نسبة نصيب الأرباح المعفاة من الضريبة'),'unit'=>__('٪')]]]];
   @endphp
   <div class="settings-cards">
   @foreach($ownerCards as $ownerCard)<div class="settings-card"><div class="settings-card-head"><h3>{{ $ownerCard['title'] }}</h3><span>{{ $ownerCard['subtitle'] }}</span></div><div class="settings-card-grid">@foreach($ownerCard['fields'] as $field)<div class="settings-field"><label class="form-label">{{ $field['label'] }}</label><div class="input-group settings-unit"><input type="number" step="0.000001" min="0" name="settings[{{ $field['key'] }}]" value="{{ format_number(old('settings.'.$field['key'], $globalPayrollValues[$field['key']] ?? '')) }}" class="form-control" required><span class="input-group-text">{{ $field['unit'] }}</span></div>@if(!empty($field['hint']))<small class="settings-hint">{{ $field['hint'] }}</small>@endif</div>@endforeach</div></div>@endforeach
   </div>
   <div class="owner-rules-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> {{ __('حفظ وتطبيق على كل الشركات') }}</button></div>
  </form>

  </div>
  <div id="ownerTaxPane" class="settings-tab-pane" hidden>
  <p class="muted" style="margin:0 0 12px">{{ __('كل شريحة تبدأ من حيث انتهت السابقة، وآخر شريحة وآخر فئة بدون حد أعلى.') }}</p>
  @error('payroll_tax')<div class="alert alert-danger">{{ $message }}</div>@enderror
  <form method="POST" action="{{ route('owner.payroll-rules.tax') }}" id="ownerTaxForm" onsubmit="ownerTaxReindex();return confirm('{{ __('سيتم تطبيق الشرائح على كل الشركات وإعادة حساب الرواتب. متابعة؟') }}')">@csrf @method('PUT')
   <div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> {{ __('التعديلات الجديدة تسري من التاريخ المحدد') }}</strong><span>{{ __('حدد التاريخ الذي تبدأ منه الشرائح الجديدة.') }}</span></div><div class="settings-effective-field"><label class="form-label">{{ __('تاريخ السريان') }}</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div></div>
   <div class="settings-cards" id="ownerTaxCategories">
   @foreach($globalTaxCategories as $ci=>$category)
   <div class="settings-card owner-tax-category">
    <div class="settings-card-head"><h3>{{ $category->label }}</h3><span>{{ $category->max_income !== null ? __('حتى') . ' ' . format_number($category->max_income) . ' ' . __('ج.م سنويًا') : __('أعلى فئة') }}</span></div>
    <div class="settings-card-grid"><div class="settings-field"><label class="form-label">{{ __('اسم الفئة') }}</label><input class="form-control" data-field="label" name="categories[{{ $ci }}][label]" value="{{ $category->label }}" required></div>
    <div class="settings-field"><label class="form-label">{{ __('الحد الأعلى للدخل السنوي') }}</label><div class="input-group settings-unit"><input type="number" step="0.01" min="0" class="form-control" data-field="max_income" name="categories[{{ $ci }}][max_income]" value="{{ $category->max_income !== null ? format_number($category->max_income) : '' }}"><span class="input-group-text">{{ __('ج.م') }}</span></div><small class="settings-hint">{{ __('فارغ للفئة الأخيرة') }}</small></div></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th>{{ __('النسبة') }}</th><th></th></tr></thead><tbody>
    @foreach($category->brackets as $bi=>$bracket)
    <tr><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-field="from" name="categories[{{ $ci }}][brackets][{{ $bi }}][from]" value="{{ format_number($bracket->from_amount) }}" required></td><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-field="to" placeholder="{{ __('بلا حد') }}" name="categories[{{ $ci }}][brackets][{{ $bi }}][to]" value="{{ $bracket->to_amount !== null ? format_number($bracket->to_amount) : '' }}"></td><td><div class="input-group input-group-sm"><input type="number" step="0.0001" min="0" max="100" class="form-control" data-field="rate" name="categories[{{ $ci }}][brackets][{{ $bi }}][rate]" value="{{ round((float) $bracket->rate * 100, 4) }}" required><span class="input-group-text">٪</span></div></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-owner-tax="remove-bracket">{{ __('حذف') }}</button></td></tr>
    @endforeach
    </tbody></table></div>
    <div class="owner-rules-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-owner-tax="add-bracket"><i class="bi bi-plus"></i> {{ __('إضافة شريحة') }}</button><button type="button" class="btn btn-sm btn-outline-danger" data-owner-tax="remove-category">{{ __('حذف الفئة') }}</button></div>
   </div>
   @endforeach
   </div>
   <div class="owner-rules-actions"><button type="button" class="btn btn-outline-primary" id="ownerAddTaxCategory"><i class="bi bi-plus-circle"></i> {{ __('إضافة فئة') }}</button><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> {{ __('حفظ وتطبيق على كل الشركات') }}</button></div>
  </form>
  </div>
 </section>

 <section class="owner-panel" data-panel="notifications" hidden>
  <div class="owner-notification-grid"><article class="owner-card"><header><h2><i class="bi bi-megaphone"></i> {{ __('إرسال إشعار') }}</h2></header><form method="POST" action="{{route('owner.announcements.send')}}" class="form-grid">@csrf<label class="full">{{ __('نطاق الإرسال') }}<select name="audience" id="announcementAudience"><option value="all">{{ __('جميع الشركات') }}</option><option value="company">{{ __('شركة محددة') }}</option></select></label><label class="full" id="announcementCompanyField">{{ __('الشركة') }}<select name="company_id"><option value="">{{ __('اختر الشركة') }}</option>@foreach($companies as $company)<option value="{{$company->id}}">{{$company->name}}</option>@endforeach</select></label><label class="full">{{ __('نص الإشعار') }}<textarea name="message" rows="8" maxlength="2000" required></textarea></label><button class="btn btn-primary full"><i class="bi bi-send"></i> {{ __('إرسال الإشعار') }}</button></form></article>
  <aside class="owner-card help-card"><header><h2><i class="bi bi-lightbulb"></i> {{ __('إرشادات') }}</h2></header><ul><li>{{ __('اكتب رسالة واضحة ومباشرة.') }}</li><li>{{ __('راجع الشركة المستهدفة قبل الإرسال.') }}</li><li>{{ __('سيتم حفظ العملية في سجل العمليات.') }}</li></ul></aside></div>
 </section>

 <section class="owner-panel" data-panel="audit" hidden>
  <div class="section-toolbar"><div><h2>{{ __('سجل العمليات التشغيلية') }}</h2><p>{{ __('آخر عمليات الاشتراكات وتحديثات قاعدة البيانات.') }}</p></div><input id="auditSearch" class="search-input" placeholder="{{ __('البحث في السجل...') }}"></div>
  <article class="owner-card table-card"><div class="table-scroll"><table id="auditTable"><thead><tr><th>{{ __('العملية') }}</th><th>{{ __('المستخدم') }}</th><th>{{ __('التفاصيل') }}</th><th>{{ __('التاريخ') }}</th></tr></thead><tbody>@forelse($subscriptionActivity as $activity)<tr><td>{{$activity->action}}</td><td>{{$activity->user?->name??__('النظام')}}</td><td>{{$activity->description}}</td><td>{{$activity->created_at?->format('d/m/Y H:i')}}</td></tr>@empty<tr><td colspan="4" class="muted center">{{ __('لا توجد عمليات مسجلة.') }}</td></tr>@endforelse</tbody></table></div></article>
 </section>
</div>

@foreach($plans as $plan)<div class="modal fade" id="editPlan{{$plan->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.plans.update',$plan)}}">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('تعديل الباقة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('اسم الباقة') }}</label><input name="name" value="{{$plan->name}}" class="form-control mb-2" required><label class="form-label">{{ __('حد الموظفين') }}</label><input type="number" name="employee_limit" value="{{$plan->employee_limit}}" class="form-control mb-2" required><label class="form-label">{{ __('حد حسابات HR') }}</label><input type="number" name="hr_user_limit" value="{{$plan->hr_user_limit??5}}" class="form-control mb-2"><label class="form-label">{{ __('السعر') }}</label><input type="number" step=".01" name="price" value="{{$plan->price}}" class="form-control mb-2"><label class="form-label">{{ __('دورة الفوترة') }}</label><select name="billing_period" class="form-select mb-2" required><option value="monthly" @selected($plan->billing_period==='monthly')>{{ __('شهري') }}</option><option value="yearly" @selected($plan->billing_period==='yearly')>{{ __('سنوي') }}</option></select><label class="form-label">{{ __('المزايا') }}</label><textarea name="features" class="form-control mb-2">{{is_array($plan->features)?implode(', ',$plan->features):(string)$plan->features}}</textarea><label class="form-check"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> {{ __('باقة نشطة') }}</label></div><div class="modal-footer"><button class="btn btn-primary">{{ __('حفظ') }}</button></div></form></div></div></div>@endforeach


@if(($pendingErasureFiles ?? 0) > 0)
<div class="alert alert-warning m-3 erasure-pending" id="erasurePending">{{ __('يوجد ملفات شركة محذوفة لم يكتمل حذفها فعليًا من التخزين.') }}
 <form method="POST" action="{{route('owner.erasure.retry')}}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-dark">{{ __('إعادة محاولة حذف الملفات') }}</button></form></div>
@endif
@foreach($companies as $company) @php $d=$companyDetails[$company->id] ?? null; $sub=$d['subscription'] ?? null; $admins=$d['admins'] ?? collect(); $first=$admins->first(); @endphp
<div class="modal fade" id="companyDetails{{$company->id}}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>{{ __('تفاصيل الشركة') }}: {{$company->name}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<dl class="company-details">
<div><dt>{{ __('اسم الشركة') }}</dt><dd>{{$company->name}}</dd></div>
<div><dt>{{ __('المعرف (slug)') }}</dt><dd>{{$company->slug}}</dd></div>
<div><dt>{{ __('الحالة') }}</dt><dd>{{$company->status==='active'?__('نشطة'):__('موقوفة')}}</dd></div>
<div><dt>{{ __('البريد') }}</dt><dd>{{$company->email ?: '—'}}</dd></div>
<div><dt>{{ __('الهاتف') }}</dt><dd>{{$company->phone ?: __('غير مسجل')}}</dd></div>
<div><dt>{{ __('تاريخ التسجيل') }}</dt><dd>{{$company->created_at?->format('d/m/Y H:i') ?? '—'}}</dd></div>
<div><dt>{{ __('الباقة / حالة الاشتراك') }}</dt><dd>{{$sub?->plan?->name ?? '—'}} / {{$statusLabels[$sub?->status] ?? __('بدون اشتراك')}}</dd></div>
<div><dt>{{ __('بداية الاشتراك') }}</dt><dd>{{$sub?->starts_at?->format('d/m/Y') ?? '—'}}</dd></div>
<div><dt>{{ __('نهاية الاشتراك') }}</dt><dd>{{$sub?->ends_at?->format('d/m/Y') ?? __('بدون تاريخ انتهاء')}}</dd></div>
<div><dt>{{ __('حد الموظفين / حد حسابات HR') }}</dt><dd>{{$sub?->plan?->employee_limit ?? '—'}} / {{$sub?->plan?->hr_user_limit ?? '—'}}</dd></div>
<div><dt>{{ __('الموظفون / المستخدمون') }}</dt><dd>{{format_number($dataStats[$company->id]['employees'] ?? 0)}} / {{format_number($dataStats[$company->id]['users'] ?? 0)}}</dd></div>
<div><dt>{{ __('طلبات التجديد') }}</dt><dd>{{format_number($d['renewals'] ?? 0)}}</dd></div>
<div><dt>{{ __('سجلات الحضور') }}</dt><dd>{{format_number($d['attendance'] ?? 0)}}</dd></div>
<div><dt>{{ __('مسيرات الرواتب') }}</dt><dd>{{format_number($dataStats[$company->id]['payroll_runs'] ?? 0)}}</dd></div>
<div><dt>{{ __('الإجازات / الجزاءات') }}</dt><dd>{{format_number($dataStats[$company->id]['leaves'] ?? 0)}} / {{format_number($dataStats[$company->id]['penalties'] ?? 0)}}</dd></div>
<div><dt>{{ __('أجهزة البصمة / مستندات الموظفين') }}</dt><dd>{{format_number($d['devices'] ?? 0)}} / {{format_number($d['documents'] ?? 0)}}</dd></div>
<div><dt>{{ __('آخر نشاط') }}</dt><dd>{{ !empty($dataStats[$company->id]['last_activity']) ? \Illuminate\Support\Carbon::parse($dataStats[$company->id]['last_activity'])->format('d/m/Y H:i') : '—' }}</dd></div>
</dl>
<h6 class="mt-3">{{ __('حسابات مدير الشركة') }}</h6>
@forelse($admins as $admin)<dl class="company-details"><div><dt>{{ __('الاسم') }}</dt><dd>{{$admin->name}}</dd></div><div><dt>{{ __('البريد') }}</dt><dd>{{$admin->email}}</dd></div><div><dt>{{ __('الحالة') }}</dt><dd>{{$admin->is_active?__('مفعّل'):__('معطّل')}}</dd></div><div><dt>{{ __('آخر تسجيل دخول') }}</dt><dd>{{$admin->last_login_at ? \Illuminate\Support\Carbon::parse($admin->last_login_at)->format('d/m/Y H:i') : __('لم يسجل دخولًا')}}</dd></div></dl>@empty<p class="muted">{{ __('لا يوجد حساب مدير.') }}</p>@endforelse
<p class="muted small">{{ __('لا تُعرض كلمات المرور أو الرموز أو أي أسرار.') }}</p></div></div></div></div>

<div class="modal fade" id="companyPassword{{$company->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.companies.manager-password',$company)}}" autocomplete="off">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('إعادة تعيين كلمة مرور مدير الشركة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
@if($admins->isEmpty())<p class="text-danger">{{ __('لا يوجد حساب مدير لهذه الشركة.') }}</p>@else
<div class="alert alert-info">{{ __('الشركة') }}: {{$company->name}}@if($admins->count()===1)<br>{{ __('الحساب') }}: {{$first->name}}<br>{{$first->email}}<input type="hidden" name="user_id" value="{{$first->id}}">@else<br><label class="form-label mt-2">{{ __('حساب المدير') }}</label><select name="user_id" class="form-select">@foreach($admins as $admin)<option value="{{$admin->id}}">{{$admin->name}} - {{$admin->email}}</option>@endforeach</select>@endif</div>
<label class="form-label">{{ __('كلمة المرور الجديدة') }}</label><input type="password" name="password" class="form-control" minlength="8" maxlength="128" autocomplete="new-password" required><small class="d-block text-muted mt-1">{{ __('8 أحرف على الأقل. تُحفظ مشفّرة ولا يمكن عرضها بعد الحفظ.') }}</small>
<label class="form-label d-block mt-3">{{ __('تأكيد كلمة المرور') }}</label><input type="password" name="password_confirmation" class="form-control" minlength="8" maxlength="128" autocomplete="new-password" required>
<p class="small mt-2" style="color:#92400e">{{ __('سيتم إنهاء جلسات المدير المفتوحة (تتطلب تسجيل دخول جديد بالكلمة الجديدة)، وتُسجَّل العملية في سجل المالك بدون كلمة المرور.') }}</p>@endif</div><div class="modal-footer"><button class="btn btn-primary" @disabled($admins->isEmpty())>{{ __('حفظ وتطبيق فورًا') }}</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button></div></form></div></div></div>

<div class="modal fade company-delete-modal" id="companyDelete{{$company->id}}" tabindex="-1" data-company-name="{{$company->name}}"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.companies.destroy',$company)}}" autocomplete="off">@csrf @method('DELETE')<div class="modal-header"><h5 class="text-danger">{{ __('حذف شركة نهائيًا') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<div class="alert alert-danger">{{ __('لا يمكن التراجع. سيتم حذف الشركة وكل بياناتها وملفاتها نهائيًا.') }}</div>
<p>{{ __('الشركة') }}: <strong>{{$company->name}}</strong></p>
<ul class="erasure-counts small"><li class="muted">{{ __('جارٍ حساب ما سيُحذف...') }}</li></ul>
<p class="small muted">{{ __('لن يُحذف: الباقات المشتركة وخطوات الجولة وبيانات الشركات الأخرى. لا يُحتفظ بأي سجل باسم الشركة بعد الحذف.') }}</p>
<label class="form-label">{{ __('اكتب اسم الشركة بالضبط لتفعيل الزر') }}</label><input name="confirm_name" class="form-control confirm-name" autocomplete="off" required></div><div class="modal-footer"><button class="btn btn-danger confirm-delete" disabled>{{ __('حذف نهائي') }}</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button></div></form></div></div></div>
@endforeach
@foreach($companies as $company) @php $subscription=$latestSubscriptions[$company->id] ?? null; @endphp<div class="modal fade" id="companySubscription{{$company->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('owner.companies.subscription.update',$company)}}">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('إدارة اشتراك') }} {{$company->name}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('الباقة') }}</label><select name="plan_id" class="form-select mb-2">@foreach($plans as $plan)<option value="{{$plan->id}}" @selected($subscription?->subscription_plan_id===$plan->id)>{{$plan->name}}</option>@endforeach</select><label class="form-label">{{ __('حالة الاشتراك') }}</label><select name="status" class="form-select mb-2">@foreach($statusLabels as $key=>$label)<option value="{{$key}}" @selected($subscription?->status===$key)>{{$label}}</option>@endforeach</select><label class="form-label">{{ __('تاريخ الانتهاء') }}</label><input type="date" name="ends_at" class="form-control mb-2" value="{{$subscription?->ends_at?->format('Y-m-d')}}"><label class="form-label">{{ __('أيام التجربة') }}</label><input type="number" min="0" name="trial_days" class="form-control" value="{{$subscription?->trial_days??0}}"></div><div class="modal-footer"><button class="btn btn-primary">{{ __('حفظ الاشتراك') }}</button></div></form><form method="POST" action="{{route('owner.companies.status.update',$company)}}" class="p-3">@csrf @method('PUT')<input type="hidden" name="status" value="{{$company->status==='active'?'suspended':'active'}}"><button class="btn btn-sm {{$company->status==='active'?'btn-outline-danger':'btn-success'}}">{{$company->status==='active'?__('إيقاف الشركة'):__('تفعيل الشركة')}}</button></form></div></div></div>@endforeach
@endsection

@push('scripts')
<script>
function openOwnerTab(name){
  document.querySelectorAll('.owner-tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));
  document.querySelectorAll('.owner-panel').forEach(p=>{p.classList.toggle('active',p.dataset.panel===name);p.hidden=p.dataset.panel!==name;});
}
document.querySelectorAll('.owner-tab').forEach(t=>t.addEventListener('click',()=>openOwnerTab(t.dataset.tab)));
const preferredOwnerTab=@json(session('open_owner_tab'));
if(preferredOwnerTab)openOwnerTab(preferredOwnerTab);
document.getElementById('toggleCompanyForm')?.addEventListener('click',()=>{const f=document.getElementById('company-form');f.hidden=!f.hidden;});
document.getElementById('togglePlanForm')?.addEventListener('click',()=>{const f=document.getElementById('plan-form');f.hidden=!f.hidden;});
const companySearch=document.getElementById('companySearch'),companyStatus=document.getElementById('companyStatus');
function filterCompanies(){const q=(companySearch?.value||'').trim(),s=companyStatus?.value||'';document.querySelectorAll('#companiesTable tbody tr').forEach(r=>{const okQ=!q||r.textContent.includes(q),okS=!s||r.querySelector('[data-status]')?.dataset.status===s;r.style.display=(okQ&&okS)?'':'none';});}
companySearch?.addEventListener('input',filterCompanies);companyStatus?.addEventListener('change',filterCompanies);
document.getElementById('auditSearch')?.addEventListener('input',e=>{const q=e.target.value.trim();document.querySelectorAll('#auditTable tbody tr').forEach(r=>{r.style.display=!q||r.textContent.includes(q)?'':'none';});});

const erasureLabels={employees:@json(__('موظف')),users:@json(__('مستخدم')),attendance:@json(__('سجل حضور')),payroll_runs:@json(__('مسير رواتب')),payroll_records:@json(__('سجل راتب')),salaries:@json(__('هيكل راتب')),leaves:@json(__('إجازة')),penalties:@json(__('جزاء')),documents:@json(__('مستند موظف')),fingerprint_devices:@json(__('جهاز بصمة')),roles:@json(__('دور')),files:@json(__('ملف مرفوع (صور ومرفقات وملفات استيراد)'))};
document.querySelectorAll('.company-delete-modal').forEach(m=>{
  const btn=document.querySelector('[data-bs-target="#'+m.id+'"]'),list=m.querySelector('.erasure-counts'),input=m.querySelector('.confirm-name'),go=m.querySelector('.confirm-delete');
  const sync=()=>{go.disabled=input.value!==m.dataset.companyName;};input.addEventListener('input',sync);
  m.addEventListener('show.bs.modal',()=>{input.value='';sync();list.innerHTML='<li class="muted">'+@json(__('جارٍ حساب ما سيُحذف...')).replace(/</g,'&lt;')+'</li>';
    fetch(btn.dataset.previewUrl,{headers:{'Accept':'application/json'},credentials:'same-origin'}).then(r=>r.json().then(j=>({ok:r.ok,j}))).then(({ok,j})=>{
      list.innerHTML='';if(!ok){const li=document.createElement('li');li.textContent=j.message||'';list.appendChild(li);go.disabled=true;input.disabled=true;return;}
      input.disabled=false;const rows=Object.entries(j.counts).filter(([k])=>erasureLabels[k]);
      rows.forEach(([k,n])=>{const li=document.createElement('li');li.textContent=n+' '+erasureLabels[k];list.appendChild(li);});
      const other=Object.entries(j.counts).filter(([k])=>!erasureLabels[k]).reduce((a,[,n])=>a+n,0);
      if(other){const li=document.createElement('li');li.textContent=other+' '+@json(__('سجل آخر (إعدادات وقوائم وأدوار وسجلات تابعة)'));list.appendChild(li);}
    }).catch(()=>{list.innerHTML='';const li=document.createElement('li');li.textContent=@json(__('تعذر حساب المعاينة.'));list.appendChild(li);go.disabled=true;});});
});
const aud=document.getElementById('announcementAudience'),compField=document.getElementById('announcementCompanyField');
function syncAudience(){if(compField)compField.style.display=aud&&aud.value==='company'?'':'none';}
aud?.addEventListener('change',syncAudience);syncAudience();
</script>
<script>document.querySelectorAll('[data-owner-rules-tab]').forEach(function(b){b.addEventListener('click',function(){document.querySelectorAll('[data-owner-rules-tab]').forEach(function(x){x.classList.toggle('active',x===b)});['ownerRulesPane','ownerTaxPane'].forEach(function(id){document.getElementById(id).hidden=id!==b.dataset.ownerRulesTab})})});</script>
<script>
(function(){
  const wrap=document.getElementById('ownerTaxCategories'); if(!wrap) return;
  const rowHtml='<tr><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-field="from" required></td><td><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-field="to" placeholder="بلا حد"></td><td><div class="input-group input-group-sm"><input type="number" step="0.0001" min="0" max="100" class="form-control" data-field="rate" required><span class="input-group-text">٪</span></div></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-owner-tax="remove-bracket">حذف</button></td></tr>';
  const catHtml='<div class="settings-card owner-tax-category"><div class="settings-card-head"><h3>فئة جديدة</h3><span>أدخل الاسم والشرائح</span></div><div class="settings-card-grid"><div class="settings-field"><label class="form-label">اسم الفئة</label><input class="form-control" data-field="label" required></div><div class="settings-field"><label class="form-label">الحد الأعلى للدخل السنوي</label><div class="input-group settings-unit"><input type="number" step="0.01" min="0" class="form-control" data-field="max_income"><span class="input-group-text">ج.م</span></div><small class="settings-hint">فارغ للفئة الأخيرة</small></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>من</th><th>إلى</th><th>النسبة</th><th></th></tr></thead><tbody>'+rowHtml+'</tbody></table></div><div class="owner-rules-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-owner-tax="add-bracket"><i class="bi bi-plus"></i> إضافة شريحة</button><button type="button" class="btn btn-sm btn-outline-danger" data-owner-tax="remove-category">حذف الفئة</button></div></div>';
  window.ownerTaxReindex=function(){
    wrap.querySelectorAll('.owner-tax-category').forEach(function(cat,ci){
      cat.querySelectorAll('[data-field]').forEach(function(input){
        const f=input.dataset.field;
        if(f==='label'||f==='max_income'){input.name='categories['+ci+']['+f+']';}
        else{const row=input.closest('tr');const bi=Array.prototype.indexOf.call(row.parentNode.children,row);input.name='categories['+ci+'][brackets]['+bi+']['+f+']';}
      });
    });
  };
  wrap.addEventListener('click',function(e){
    const b=e.target.closest('[data-owner-tax]'); if(!b) return;
    const a=b.dataset.ownerTax;
    if(a==='add-bracket') b.closest('.owner-tax-category').querySelector('tbody').insertAdjacentHTML('beforeend',rowHtml);
    if(a==='remove-bracket') b.closest('tr').remove();
    if(a==='remove-category') b.closest('.owner-tax-category').remove();
    ownerTaxReindex();
  });
  document.getElementById('ownerAddTaxCategory').addEventListener('click',function(){wrap.insertAdjacentHTML('beforeend',catHtml);ownerTaxReindex();});
})();
</script>

@include('owner._styles')
@endpush
