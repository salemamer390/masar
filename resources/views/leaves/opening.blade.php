@extends('layouts.app')
@section('title', __('الأرصدة الافتتاحية للإجازات'))
@section('content')
<div class="page-head"><div><h1>{{ __('الأرصدة الافتتاحية وترحيل الإجازات') }}</h1><p>{{ __('تحديد نقطة بدء تتبع الترحيل وإدخال الأرصدة القديمة المؤكدة لكل موظف. لا يفترض النظام أي رصيد قديم بدون إدخال صريح هنا.') }}</p></div></div>

<div class="panel table-panel mb-4"><div class="panel-head"><div><h3>{{ __('تاريخ بدء تتبع الترحيل') }}</h3><span>{{ __('الدورات التي تبدأ في هذا التاريخ أو بعده تُحسب من سجلات النظام كاملة. ما قبلها لا يترحّل إلا بإدخال افتتاحي صريح.') }} @if($baseline){{ __('الحالي:') }} <strong>{{ $baseline->format('d/m/Y') }}</strong>@else<span class="badge-soft warning">{{ __('غير مفعّل بعد') }}</span> {{ __('— لن يُحفظ أي تاريخ إلا بضغطك «تفعيل» هنا؛ حتى ذلك الحين يُعامل اليوم كحدّ أقصى للتتبع دون حفظ.') }}@endif</span></div></div>
<div class="p-3"><form method="POST" action="{{ route('leaves.opening.baseline') }}" class="d-flex gap-2 align-items-end flex-wrap">@csrf @method('PUT')
<div><label class="form-label">{{ __('تاريخ البدء') }}</label><input type="date" name="baseline" class="form-control" value="{{ $proposedBaseline->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
<button class="btn btn-primary"><i class="bi bi-calendar-check"></i> {{ $baseline ? 'حفظ التاريخ' : 'تفعيل تاريخ البدء' }}</button></form></div></div>

<div class="panel table-panel"><div class="panel-head"><div><h3>{{ __('موظفون بتاريخ سابق لبدء التتبع') }}</h3><span>{{ __('أدخل الرصيد المرحّل المؤكد لكل سنة دورة، أو أكّد عدم وجود رصيد. الإدخال يُحفظ باسمك وتاريخه وسببه.') }}</span></div></div>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('الموظف') }}</th><th>{{ __('تاريخ بداية العقد') }}</th><th>{{ __('سنة الدورة') }}</th><th>{{ __('الرصيد الافتتاحي (يوم)') }}</th><th>{{ __('تأكيد صفر') }}</th><th>{{ __('السبب / المصدر') }}</th><th>{{ __('الإدخالات المحفوظة') }}</th><th></th></tr></thead><tbody>
@forelse($employees as $employee)
@php($firstCycleYear = \Carbon\Carbon::parse($employee->contract_start_date)->year)
<tr><td>{{ $employee->full_name }}<br><small class="text-muted">{{ $employee->employee_code }}</small></td><td>{{ \Carbon\Carbon::parse($employee->contract_start_date)->format('d/m/Y') }}</td><td colspan="6">
@foreach($entries->get($employee->id, collect()) as $entry)
<div class="small mb-1">{{ __('دورة') }} {{ $entry->cycle_year }}: {{ $entry->is_confirmed_zero ? 'مؤكد صفر' : format_number((float) $entry->amount) }} {{ __('يوم —') }} {{ $entry->reason }} <span class="text-muted">({{ $entry->creator?->name ?? '—' }}{{ __('،') }} {{ $entry->updated_at?->format('d/m/Y') }})</span></div>
@endforeach
<form method="POST" action="{{ route('leaves.opening.store') }}" class="d-flex gap-2 align-items-center flex-wrap">@csrf
<input type="hidden" name="employee_id" value="{{ $employee->id }}">
<input type="number" name="cycle_year" class="form-control" style="max-width:110px" value="{{ $firstCycleYear }}" min="2000" max="2100" required title="{{ __('سنة الدورة') }}">
<input type="number" name="amount" class="form-control" style="max-width:110px" step="0.5" min="-999" max="999" placeholder="{{ __('أيام') }}">
<label class="form-check"><input type="checkbox" name="is_confirmed_zero" value="1" class="form-check-input"> <span class="form-check-label">{{ __('تأكيد عدم وجود رصيد') }}</span></label>
<input type="text" name="reason" class="form-control" style="min-width:220px" maxlength="500" placeholder="{{ __('السبب / مصدر التأكيد') }}" required>
<button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> {{ __('حفظ') }}</button></form>
</td></tr>
@empty<tr><td colspan="8" class="text-center py-5">{{ __('لا يوجد موظفون بتاريخ تعيين سابق لبدء التتبع.') }}</td></tr>
@endforelse
</tbody></table></div></div>
@endsection
