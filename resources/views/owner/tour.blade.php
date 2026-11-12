@extends('layouts.owner')
@section('title',__('إدارة الجولة التعريفية'))
@section('content')
@include('owner._styles')
<div class="owner-page">
 <div class="owner-heading">
  <div>
   <h1><i class="bi bi-signpost-2-fill"></i> {{ __('إدارة الجولة التعريفية') }}</h1>
   <p>{{ __('تعديل نصوص الجولة الإجبارية وترتيبها وإضافة خطوات لها — التعديل يتم على مسودة، ولا يراه المستخدمون إلا بعد النشر.') }}</p>
  </div>
  <a class="btn btn-outline btn-sm" href="{{ route('owner.index') }}"><i class="bi bi-arrow-right"></i> {{ __('عودة للوحة المالك') }}</a>
 </div>

 @if(!$tableReady)
  <article class="owner-card"><div class="tour-warning">
   <i class="bi bi-exclamation-triangle"></i>
   {{ __('جدول خطوات الجولة غير موجود بعد. شغّل') }} <code dir="ltr">php artisan migrate</code> {{ __('ثم أعد فتح الصفحة.') }}
  </div></article>
 @else
 <div class="tour-status-grid">
  <article class="owner-card tour-status-card">
   <header><h2><i class="bi bi-broadcast"></i> {{ __('الجولة المنشورة') }}</h2></header>
   <p>{{ __('عدد الخطوات:') }} <b>{{ format_number($liveCount) }}</b></p>
   <p>{{ __('آخر نشر:') }} <b>{{ $lastPublished ? \Illuminate\Support\Carbon::parse($lastPublished)->format('d/m/Y H:i') : __('لم ينشر بعد') }}</b></p>
   <p class="muted">{{ __('تعديل النصوص لا يعيد الجولة تلقائيًا لمن أنهاها، إلا إذا طلبت ذلك عند النشر.') }}</p>
  </article>
  <article class="owner-card tour-status-card">
   <header><h2><i class="bi bi-pencil-square"></i> {{ __('المسودة') }}</h2></header>
   @if($hasDraft)
    <p><span class="owner-status active">{{ __('توجد مسودة قيد التعديل') }}</span></p>
    <div class="tour-actions-row">
     <a class="btn btn-outline btn-sm" target="_blank" href="{{ route('dashboard') }}?tour_preview=1"><i class="bi bi-eye"></i> {{ __('معاينة المسودة') }}</a>
     <form method="POST" action="{{ route('owner.tour.publish') }}" onsubmit="return confirm('{{ __('نشر المسودة لكل المستخدمين؟') }}')">
      @csrf
      <label class="tour-reshow"><input type="checkbox" name="reshow" value="1"> {{ __('أعد إظهار الجولة لكل المستخدمين من الأول') }}</label>
      <button class="btn btn-primary btn-sm"><i class="bi bi-cloud-upload"></i> {{ __('نشر') }}</button>
     </form>
     <form method="POST" action="{{ route('owner.tour.draft.discard') }}" onsubmit="return confirm('{{ __('تجاهل المسودة بالكامل؟') }}')">
      @csrf @method('DELETE')
      <button class="btn btn-outline-danger btn-sm">{{ __('تجاهل المسودة') }}</button>
     </form>
    </div>
   @else
    <p><span class="owner-status expired">{{ __('لا توجد مسودة') }}</span></p>
    <div class="tour-actions-row">
     <form method="POST" action="{{ route('owner.tour.draft.start') }}">@csrf<button class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> {{ __('بدء التعديل') }}</button></form>
     <a class="btn btn-outline btn-sm" target="_blank" href="{{ route('dashboard') }}?tour_preview=1"><i class="bi bi-eye"></i> {{ __('معاينة المنشور') }}</a>
    </div>
   @endif
  </article>
  <article class="owner-card tour-status-card">
   <header><h2><i class="bi bi-cursor"></i> {{ __('إضافة خطوة بالضغط على الزر') }}</h2></header>
   <ol class="tour-help-list">
    <li>{{ __('افتح الصفحة المطلوبة في اودير وأنت مسجل دخولك.') }}</li>
    <li>{{ __('أضف') }} <code dir="ltr">?tour_edit=1</code> {{ __('لآخر الرابط.') }}</li>
    <li>{{ __('اضغط على الزر أو العنصر، اكتب العنوان والشرح، واحفظ.') }}</li>
    <li>{{ __('ارجع لهذه الصفحة للمعاينة والنشر.') }}</li>
   </ol>
  </article>
 </div>

 <article class="owner-card table-card">
  <header>
   <h2><i class="bi bi-list-ol"></i> {{ $hasDraft ? __('خطوات المسودة') : __('الخطوات المنشورة حاليًا') }}</h2>
   @if(!$hasDraft)<span class="muted">{{ __('للقراءة فقط — ابدأ التعديل لإنشاء مسودة.') }}</span>@endif
  </header>
  <div class="table-scroll"><table class="tour-steps-table">
   <thead><tr>
    <th style="width:90px">{{ __('الترتيب') }}</th>
    <th>{{ __('الخطوة') }}</th>
    <th>{{ __('الصفحة') }}</th>
    <th>{{ __('العنصر المحدد') }}</th>
    <th>{{ __('الصلاحية') }}</th>
    <th>{{ __('الحالة') }}</th>
    @if($hasDraft)<th></th>@endif
   </tr></thead>
   <tbody>
   @foreach($steps as $step)
    <tr class="{{ $step->is_active ? '' : 'tour-step-off' }}">
     <td>
      <span class="tour-order">{{ $loop->iteration }}</span>
      @if($hasDraft)
       <form method="POST" action="{{ route('owner.tour.steps.move', $step) }}" class="inline-form">@csrf<input type="hidden" name="direction" value="up"><button class="tour-move" title="{{ __('أعلى') }}" @disabled($loop->first)>▲</button></form>
       <form method="POST" action="{{ route('owner.tour.steps.move', $step) }}" class="inline-form">@csrf<input type="hidden" name="direction" value="down"><button class="tour-move" title="{{ __('أسفل') }}" @disabled($loop->last)>▼</button></form>
      @endif
     </td>
     <td>
      <i class="bi {{ $step->icon ?: 'bi-hand-index' }}"></i>
      <b>{{ $step->title_ar }}</b>
      @if($step->is_system)<span class="owner-status active">{{ __('أساسية') }}</span>@endif
      <div class="muted tour-step-body">{{ Str::limit($step->body_ar, 90) }}</div>
     </td>
     <td dir="ltr" class="tour-mono">{{ $step->route_name ?: '—' }}</td>
     <td dir="ltr" class="tour-mono">{{ $step->target[0] ?? '—' }}</td>
     <td dir="ltr" class="tour-mono">{{ $step->permission ?: __('الكل') }}</td>
     <td><span class="owner-status {{ $step->is_active ? 'active' : 'suspended' }}">{{ $step->is_active ? __('ظاهرة') : __('موقوفة') }}</span></td>
     @if($hasDraft)
      <td class="tour-step-actions">
       <button class="btn btn-outline btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#editTourStep{{ $step->id }}">{{ __('تعديل') }}</button>
       <form method="POST" action="{{ route('owner.tour.steps.toggle', $step) }}" class="inline-form">@csrf<button class="btn btn-outline btn-sm">{{ $step->is_active ? __('إيقاف') : __('تفعيل') }}</button></form>
       @unless($step->is_system)
        <form method="POST" action="{{ route('owner.tour.steps.destroy', $step) }}" class="inline-form" onsubmit="return confirm('{{ __('حذف الخطوة من المسودة؟') }}')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('حذف') }}</button></form>
       @endunless
      </td>
     @endif
    </tr>
   @endforeach
   </tbody>
  </table></div>
 </article>

 @if($hasDraft)
 <article class="owner-card">
  <header><h2><i class="bi bi-plus-square"></i> {{ __('إضافة خطوة يدويًا') }}</h2></header>
  <form method="POST" action="{{ route('owner.tour.steps.store') }}" class="form-grid">
   @csrf
   <label class="full">{{ __('الصفحة') }}
    <select name="route_name" required>
     <option value="">{{ __('اختر الصفحة') }}</option>
     @foreach($routes as $name => $label)<option value="{{ $name }}">{{ $label }}</option>@endforeach
    </select>
   </label>
   <label class="full">{{ __('محدد العنصر (CSS selector)') }}
    <input name="selector" dir="ltr" required placeholder="#newRequestButton">
    <small class="muted">{{ __('الأفضل معرّف id للزر. يُضاف') }} <code dir="ltr">.page-head</code> {{ __('تلقائيًا كاحتياطي لو اختفى العنصر.') }}</small>
   </label>
   <label>{{ __('عنوان الخطوة (عربي)') }}<input name="title_ar" required maxlength="500"></label>
   <label>{{ __('الأيقونة') }}<input name="icon" dir="ltr" placeholder="bi-hand-index"></label>
   <label class="full">{{ __('الشرح (عربي)') }}<textarea name="body_ar" rows="2" required maxlength="2000"></textarea></label>
   <label>{{ __('العنوان (إنجليزي — اختياري)') }}<input name="title_en" maxlength="500"></label>
   <label>{{ __('الصلاحية المطلوبة (اختياري)') }}
    <select name="permission">
     <option value="">{{ __('بدون صلاحية — تظهر للجميع') }}</option>
     @foreach($permissions as $permission)<option value="{{ $permission }}">{{ $permission }}</option>@endforeach
    </select>
   </label>
   <label class="full">{{ __('الشرح (إنجليزي — اختياري)') }}<textarea name="body_en" rows="2" maxlength="2000"></textarea></label>
   <button class="btn btn-primary full">{{ __('إضافة إلى المسودة') }}</button>
  </form>
 </article>

 @foreach($steps as $step)
 <div class="modal fade" id="editTourStep{{ $step->id }}" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
   <form method="POST" action="{{ route('owner.tour.steps.update', $step) }}">
    @csrf @method('PUT')
    <div class="modal-header"><h5>{{ __('تعديل الخطوة') }} — {{ $step->title_ar }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
     <label class="form-label">{{ __('العنوان (عربي)') }}</label>
     <input name="title_ar" class="form-control mb-2" value="{{ $step->title_ar }}" required maxlength="500">
     <label class="form-label">{{ __('الشرح (عربي)') }}</label>
     <textarea name="body_ar" class="form-control mb-2" rows="3" required maxlength="2000">{{ $step->body_ar }}</textarea>
     <label class="form-label">{{ __('العنوان (إنجليزي)') }}</label>
     <input name="title_en" class="form-control mb-2" dir="ltr" value="{{ $step->title_en }}" maxlength="500">
     <label class="form-label">{{ __('الشرح (إنجليزي)') }}</label>
     <textarea name="body_en" class="form-control mb-2" dir="ltr" rows="2" maxlength="2000">{{ $step->body_en }}</textarea>
     <label class="form-label">{{ __('الأيقونة') }}</label>
     <input name="icon" class="form-control mb-2" dir="ltr" value="{{ $step->icon }}" placeholder="bi-hand-index">
     @if($step->route_name)
      <label class="form-label">{{ __('محددات العنصر (سطر لكل محدد، الأخير هو الاحتياطي)') }}</label>
      <textarea name="target" class="form-control" dir="ltr" rows="{{ min(4, max(2, count($step->target ?? []))) }}">{{ implode("\n", $step->target ?? []) }}</textarea>
     @endif
    </div>
    <div class="modal-footer"><button class="btn btn-primary">{{ __('حفظ') }}</button></div>
   </form>
  </div></div>
 </div>
 @endforeach
 @endif
 @endif
</div>

<style>
.tour-warning{padding:18px;display:flex;gap:10px;align-items:center;color:#92400e;font-weight:700}
.tour-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}
.tour-status-card p{margin:4px 0;font-size:13px}
.tour-actions-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px}
.tour-actions-row form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tour-reshow{font-size:12px;font-weight:700;color:#60708c;display:flex;align-items:center;gap:5px}
.tour-help-list{margin:0;padding-inline-start:18px;font-size:13px;color:#475569;display:flex;flex-direction:column;gap:4px}
.tour-steps-table td{vertical-align:middle;font-size:13px}
.tour-order{display:inline-block;min-width:22px;text-align:center;font-weight:800;color:#1268f3}
.tour-move{border:1px solid #dbe3ef;background:#f8fafc;border-radius:6px;padding:0 6px;font-size:10px;cursor:pointer;color:#475569}
.tour-move:disabled{opacity:.3;cursor:default}
.tour-mono{font-family:monospace;font-size:11px;text-align:start}
.tour-step-body{font-size:11px}
.tour-step-off td{opacity:.55}
.tour-step-actions{display:flex;gap:6px;flex-wrap:wrap}
@media (max-width: 1100px){.tour-status-grid{grid-template-columns:1fr}}
</style>
@endsection
