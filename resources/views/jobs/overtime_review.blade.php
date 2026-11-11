@extends('layouts.app')
@section('title', __('حضور إضافي بدون طلب'))
@section('content')
<div class="page-head"><div><h1>{{ __('حضور إضافي بدون طلب') }}</h1><p>{{ __('أيام فيها حضور خارج الشيفت لم يغطّها أي طلب إضافي بمواعيد، خلال الفترة المحددة. التنبيه للمراجعة فقط، وتسجيل الطلبات يتم من صفحة الطلبات.') }}</p></div><span class="badge-soft info px-3 py-2"><i class="bi bi-calendar3"></i> {{ $month->format('m/Y') }}</span></div>
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET"><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"><div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو الكود') }}"></div><button class="btn btn-primary"><i class="bi bi-funnel"></i> {{ __('عرض') }}</button><a class="btn btn-light" href="{{ route('jobs.overtime-review') }}">{{ __('مسح') }}</a><a class="btn btn-outline-primary" href="{{ route('requests.index') }}"><i class="bi bi-plus-circle"></i> {{ __('تسجيل طلب إضافي') }}</a></form>
<div class="panel table-panel"><div class="panel-head"><div><h3>{{ __('الأيام المطلوب مراجعتها') }}</h3><span>{{ __('الحضور خارج الشيفت لا يُحتسب إضافيًا إلا بطلب بمواعيد من/إلى يغطيه.') }}</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('الكود') }}</th><th>{{ __('اسم الموظف') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('إضافي نهاري بدون طلب (دقيقة)') }}</th><th>{{ __('إضافي ليلي بدون طلب (دقيقة)') }}</th><th>{{ __('الإجمالي (دقيقة)') }}</th></tr></thead><tbody>
@forelse($entries as $entry) @php($employee = $entry['employee'])<tr><td>{{ $employee->employee_code }}</td><td><a class="employee-link fw-bold" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></td><td>{{ \Carbon\Carbon::parse($entry['date'])->format('d/m/Y') }}</td><td>{{ format_number($entry['day_minutes']) }}</td><td>{{ format_number($entry['night_minutes']) }}</td><td>{{ format_number($entry['day_minutes'] + $entry['night_minutes']) }}</td></tr>@empty<tr><td colspan="6" class="text-center py-5">{{ __('لا توجد أيام حضور إضافي بدون طلب في هذه الفترة.') }}</td></tr>@endforelse
</tbody></table></div></div>
@if($entries->hasPages())
<div class="d-flex justify-content-center mt-4">{{ $entries->links() }}</div>
@endif
@endsection
