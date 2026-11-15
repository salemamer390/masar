@extends('layouts.app')
@section('title', __('سجلات مستوردة بتاريخ قديم'))
@section('content')
<div class="import-outofperiod-page">
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h3>{{ __('سجلات مستوردة بتاريخ قبل فترة المرتب الحالية') }}</h3>
        <p class="text-muted mb-0">{{ __('تبدأ فترة المرتب الحالية في :date. القائمة تعرض فقط السجلات التي أنشأها استيراد Excel؛ الإدخالات اليدوية ومسيرات الرواتب لا تظهر ولا تُحذف هنا.', ['date' => $cutoff]) }}</p>
    </div>
    <a class="btn btn-light" href="{{ route('employees.index') }}">{{ __('الموظفون') }}</a>
</div>
@php $total = collect($groups)->sum(fn ($rows) => $rows->count()); @endphp
@if($total === 0)
    <div class="alert alert-success">{{ __('لا توجد سجلات مستوردة بتاريخ قديم تحتاج إلى تنظيف.') }}</div>
@else
<form method="POST" action="{{ route('employees.import-outofperiod.destroy') }}" onsubmit="return confirm(@json(__('سيُحذف كل صف محدد نهائيًا من سجلات الاستيراد فقط. هل راجعت القائمة وتريد المتابعة؟')));">
    @csrf @method('DELETE')
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle ms-1"></i>{{ __('راجع العدد والتواريخ أولًا. الحذف نهائي ويقتصر على الصفوف المحددة أدناه.') }}</div>
    @foreach($groups as $domain => $rows)
        @if($rows->isNotEmpty())
        <div class="panel table-panel mb-3">
            <div class="panel-head"><h5 class="mb-0">{{ $labels[$domain] }} <span class="badge bg-warning text-dark">{{ $rows->count() }}</span></h5></div>
            <table class="table align-middle"><thead><tr><th style="width:44px"><input class="form-check-input" type="checkbox" data-check-all="{{ $domain }}" aria-label="{{ __('تحديد الكل') }}"></th><th>{{ __('الموظف') }}</th><th>{{ __('النوع') }}</th><th>{{ __('من تاريخ') }}</th><th>{{ __('إلى تاريخ') }}</th><th>{{ __('دفعة الاستيراد') }}</th></tr></thead><tbody>
            @foreach($rows as $row)
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="ids[{{ $domain }}][]" value="{{ $row['id'] }}" data-check-item="{{ $domain }}" aria-label="{{ __('اختيار السجل') }}"></td>
                    <td>{{ $row['employee'] }}</td><td>{{ $row['kind'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['end'] ?? '—' }}</td><td>#{{ $row['batch'] }}</td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @endif
    @endforeach
    <button class="btn btn-danger"><i class="bi bi-trash ms-1"></i>{{ __('حذف السجلات المحددة') }}</button>
</form>
@push('styles')
<style>
.import-outofperiod-page{max-width:min(1180px,100%);margin-inline:auto;overflow-wrap:anywhere}
.import-outofperiod-page .table-panel{max-width:100%;overflow-x:auto}
.import-outofperiod-page table{min-width:720px}
</style>
@endpush
@push('scripts')
<script>document.querySelectorAll('[data-check-all]').forEach(master=>master.addEventListener('change',()=>{document.querySelectorAll('[data-check-item="'+master.dataset.checkAll+'"]').forEach(box=>box.checked=master.checked);}));</script>
@endpush
@endif
</div>
@endsection
