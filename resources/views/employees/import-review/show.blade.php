@extends('layouts.app')
@section('title',__('مراجعة صف مرفوض'))
@section('content')
<a href="{{ route('employees.import-batches.index') }}">{{ __('صفوف الاستيراد المرفوضة') }}</a>
<h3 class="my-3">{{ $payload['full_name'] ?? ($payload['values'][1] ?? 'صف بدون اسم') }} · {{ $row->sheet ?? 'ورقة غير محددة' }} {{ __('· صف') }} {{ $row->line }} <span class="badge text-bg-light border">{{ \App\Services\Imports\EmployeeImportService::kindLabels()[$row->kind] ?? $row->kind }}</span></h3>
<p>{{ __('الحالة:') }} {{ ['pending'=>'تحتاج مراجعة','fixed'=>'تم التصحيح والحفظ','dismissed'=>'مستبعد مع الاحتفاظ بالأصل'][$row->status] }}@if($row->employee) · <a href="{{ route('employees.show', $row->employee) }}">{{ $row->employee->full_name }}</a>@endif</p>
<p class="small text-muted">{{ __('الملف:') }} {{ $row->batch->file_name ?? '-' }} · {{ $row->batch?->created_at?->format('d/m/Y H:i') }}</p>
@if(session('toast'))<div class="alert alert-success">{{ session('toast') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
<div class="card p-3 my-3"><h5>{{ __('سبب الرفض الأصلي') }}</h5>
@foreach($row->errors ?? [] as $error)<div class="text-danger small">{{ $error }}</div>@endforeach
</div>
@if($row->kind !== 'employees' && isset($payload['headers'], $payload['values']))
<div class="card p-3 my-3"><h5>{{ __('قيم الصف الأصلية') }}</h5>
<div class="table-responsive"><table class="table table-sm"><tbody>
@foreach($payload['headers'] as $i => $header)<tr><th class="text-muted small">{{ $header }}</th><td>{{ $payload['values'][$i] ?? '' }}</td></tr>@endforeach
</tbody></table></div>
</div>
@endif
@if($row->status==='pending')
@if($row->kind === 'employees')
<form method="post" action="{{ route('employees.import-rows.update', $row) }}" class="card p-3">@csrf @method('PUT')
<input type="hidden" name="version" value="{{ $row->revisions->count() }}">
<h5>{{ __('تصحيح الصف') }}</h5><p class="small text-muted">{{ __('عدّل القيم ثم احفظ؛ يعاد التحقق بنفس قواعد الاستيراد على البيانات الحالية، والخلايا الفارغة لا تمسح بيانات موجودة.') }}</p>
<div class="row g-2">
@foreach($fields as $key=>[$label,$aliases])
<div class="col-md-4"><label class="form-label small">{{ $label }}</label><input class="form-control form-control-sm" name="values[{{ $key }}]" value="{{ old('values.'.$key, $payload[$key] ?? '') }}" maxlength="5000"></div>
@endforeach
</div>
<label class="mt-3">{{ __('سبب التصحيح') }}</label><textarea name="reason" required maxlength="1000" class="form-control mb-2">{{ old('reason') }}</textarea>
<button class="btn btn-primary">{{ __('حفظ وإعادة التحقق') }}</button>
</form>
@else
<div class="alert alert-info small">{{ __('صفوف «') }}{{ \App\Services\Imports\EmployeeImportService::kindLabels()[$row->kind] ?? $row->kind }}{{ __('» لا تُخمَّن هنا: صحّحها من شاشة') }} {{ $row->kind === 'attendance' ? 'الحضور والانصراف' : ($row->kind === 'leaves' ? 'الإجازات' : ($row->kind === 'penalties' ? 'الجزاءات' : ($row->kind === 'insurance' ? 'التأمينات' : 'الطلبات'))) }} {{ __('أو أعد رفع ملف مُصحَّح، ثم استبعدها من هنا بعد معالجتها.') }}</div>
@endif
<form method="post" action="{{ route('employees.import-rows.destroy', $row) }}" class="card p-3 my-3">@csrf @method('DELETE')
<input type="hidden" name="version" value="{{ $row->revisions->count() }}">
<h5>{{ __('استبعاد الصف') }}</h5>
<input class="form-control mb-2" name="reason" placeholder="{{ __('سبب الاستبعاد') }}" required maxlength="1000">
<button class="btn btn-outline-danger">{{ __('استبعاد مع تسجيل السبب') }}</button>
</form>
@endif
<h5 class="mt-4">{{ __('سجل المراجعة') }}</h5>
@forelse($row->revisions as $revision)
<div class="card p-3 mb-2"><strong>{{ $revision->user?->name ?? 'حساب سابق' }} · {{ $revision->created_at->format('d/m/Y H:i') }} · {{ $revision->action === 'fix' ? 'تصحيح' : 'استبعاد' }}</strong><div>{{ $revision->reason }}</div><pre class="small mt-2" dir="ltr" style="white-space:pre-wrap">{{ json_encode(['before'=>$revision->before_data,'after'=>$revision->after_data],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div>
@empty
<p class="text-muted">{{ __('لا توجد مراجعات بعد.') }}</p>
@endforelse
@endsection
