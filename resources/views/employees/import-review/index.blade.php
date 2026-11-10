@extends('layouts.app')
@section('title',__('مراجعة صفوف استيراد الموظفين'))
@section('content')
<div class="d-flex justify-content-between mb-3"><h3>{{ __('صفوف الاستيراد المرفوضة') }} <span class="badge bg-warning text-dark">{{ $count }}</span></h3><a class="btn btn-light" href="{{ route('employees.index') }}">{{ __('الموظفون') }}</a></div>
<p class="text-muted">{{ __('الملف الأصلي لا يُحفظ. تبقى فقط الصفوف المرفوضة (مشفرة) لتصحيحها هنا مع تسجيل من عدّل ومتى ولماذا.') }}</p>
<div class="table-responsive"><table class="table"><thead><tr><th>{{ __('الملف') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الورقة') }}</th><th>{{ __('الصف') }}</th><th>{{ __('الاسم') }}</th><th>{{ __('سبب الرفض') }}</th><th></th></tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td>{{ $row->batch->file_name ?? '-' }}</td>
<td><span class="badge text-bg-light border">{{ \App\Services\Imports\EmployeeImportService::kindLabels()[$row->kind] ?? $row->kind }}</span></td>
<td>{{ $row->sheet ?? '-' }}</td>
<td>{{ $row->line }}</td>
<td>{{ $row->payload['full_name'] ?? ($row->payload['values'][1] ?? ($row->employee->full_name ?? '—')) }}</td>
<td class="small">{{ implode(' | ', $row->errors ?? []) }}</td>
<td><a class="btn btn-sm btn-outline-primary" href="{{ route('employees.import-rows.show', $row) }}">{{ __('فتح') }}</a></td>
</tr>
@empty
<tr><td colspan="7">{{ __('لا توجد صفوف معلقة للمراجعة.') }}</td></tr>
@endforelse
</tbody></table></div>{{ $rows->links() }}
<h5 class="mt-4">{{ __('ملفات الاستيراد الأخيرة') }}</h5>
@foreach($batches as $batch)
@php $dist = $batch->distributed ?? []; $distParts = []; foreach (['attendance'=>'حضور','leaves'=>'إجازات','penalties'=>'جزاءات','requests'=>'طلبات','insurance'=>'تأمينات'] as $k=>$label) { $n = (int)($dist[$k]['imported'] ?? 0); if ($n > 0) $distParts[] = $label.': '.$n; } @endphp
<div class="border rounded p-2 mb-2">
<strong>{{ $batch->file_name }}</strong> <small>{{ $batch->created_at->format('d/m/Y H:i') }}@if($batch->user) · {{ $batch->user->name }}@endif</small>
<div class="small text-muted">{{ __('جديد') }} {{ $batch->created }} {{ __('· محدَّث') }} {{ $batch->updated }} {{ __('· بدون تغيير') }} {{ $batch->unchanged }} {{ __('· متخطَّى') }} {{ $batch->skipped }} {{ __('· مرفوض') }} {{ $batch->rejected }}@if($distParts) · {{ implode(' · ', $distParts) }}@endif</div>
@if($batch->sheets)<div class="small text-muted">{{ __('الأوراق:') }} @foreach($batch->sheets as $name=>$kind)<span class="badge text-bg-light border">{{ $name }}: {{ \App\Services\Imports\EmployeeImportService::kindLabels()[$kind] ?? $kind }}</span> @endforeach</div>@endif
</div>
@endforeach
@endsection
