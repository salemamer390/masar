@extends('layouts.app')
@section('title', __('ملخص الشهر'))
@section('content')
<div class="page-head"><div><h1>{{ __('ملخص الشهر') }}</h1><p>{{ __('كشف الحضور والإجازات والجزاءات والأرصدة خلال الفترة المحددة.') }}</p></div><span class="badge-soft info px-3 py-2"><i class="bi bi-calendar3"></i> {{ $month->format('m/Y') }}</span></div>
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET" data-server-live-search="true"><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"><div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو الكود') }}"></div><select name="department_id" class="form-select"><option value="">{{ __('كل الأقسام') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><button class="btn btn-primary"><i class="bi bi-funnel"></i> {{ __('عرض') }}</button><a class="btn btn-light" href="{{ route('jobs.index') }}">{{ __('مسح') }}</a></form>
<div class="d-flex gap-2 flex-wrap mb-3"><button class="btn btn-primary" type="button" data-summary-tab="current"><i class="bi bi-table"></i> {{ __('ملخص الشهر الحالي') }}</button><button class="btn btn-light" type="button" data-summary-tab="history"><i class="bi bi-clock-history"></i> {{ __('سجل الملخص') }}</button><a class="btn btn-outline-success" href="{{ route('jobs.export', ['month' => $month->format('Y-m')]) }}"><i class="bi bi-file-earmark-excel"></i> {{ __('تصدير Excel') }}</a><form method="POST" action="{{ route('jobs.summary-history.store') }}" class="d-inline">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><button class="btn btn-outline-primary"><i class="bi bi-save"></i> {{ __('حفظ في السجل') }}</button></form></div>
@if($overtimeGapDays > 0)
<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2"><div class="d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle fs-5"></i><span class="fw-semibold">{{ __('يوجد :days يوم حضور إضافي بدون طلب يغطيه لدى :employees موظف في هذه الفترة.', ['days' => format_number($overtimeGapDays), 'employees' => format_number($overtimeGapEmployees)]) }}</span></div><a class="btn btn-sm btn-dark" href="{{ route('jobs.overtime-review', array_merge(['month' => $month->format('Y-m')], request()->only(['search', 'department_id', 'contract_status']))) }}"><i class="bi bi-list-check"></i> {{ __('مراجعة الأيام') }}</a></div>
@endif
<div id="summaryCurrent"><div class="panel table-panel"><div class="panel-head"><div><h3>{{ __('ملخص حركة الموظفين') }}</h3><span>{{ __('السجلات مؤكدة فور تسجيلها. الغياب حتى آخر يوم عمل مكتمل فقط؛ الإذن يُعفي وقته والمأمورية تُعفي أيامها.') }}</span></div></div><div class="table-responsive"><table class="table align-middle monthly-summary-table"><thead><tr><th colspan="3">{{ __('الموظف') }}</th><th colspan="4">{{ __('الحضور والغياب') }}</th><th colspan="6">{{ __('الإجازات المؤكدة داخل الفترة (أيام)') }}</th><th colspan="2">{{ __('الجزاءات') }}</th><th>{{ __('الرصيد') }}</th><th colspan="6">{{ __('الطلبات المؤكدة') }}</th></tr><tr><th>{{ __('الكود') }}</th><th>{{ __('اسم الموظف') }}</th><th>{{ __('القسم') }}</th><th>{{ __('أيام الحضور') }}</th><th>{{ __('منها حضور متأخر') }}</th><th>{{ __('دقائق التأخير') }}</th><th>{{ __('أيام الغياب') }}</th><th>{{ __('سنوية') }}</th><th>{{ __('عارضة') }}</th><th>{{ __('مرضية') }}</th><th>{{ __('بدون أجر') }}</th><th>{{ __('أمومة') }}</th><th>{{ __('أنواع أخرى') }}</th><th>{{ __('إجمالي الإجازات') }}</th><th>{{ __('عدد الجزاءات') }}</th><th>{{ __('أيام خصم الجزاءات') }}</th><th>{{ __('رصيد الإجازة السنوية المتبقي') }}</th><th>{{ __('عدد الطلبات') }}</th><th>{{ __('أيام الطلبات') }}</th><th>{{ __('ساعات الطلبات') }}</th><th>{{ __('إضافات') }}</th><th>{{ __('خصومات') }}</th><th>{{ __('أنواع الطلبات') }}</th></thead><tbody>
@forelse($monthlyRows as $row) @php($employee=$row['employee'])<tr><td>{{ $employee->employee_code }}</td><td><a class="employee-link fw-bold" href="{{ route('employees.show',$employee) }}">{{ $employee->full_name }}</a></td><td>{{ $employee->department?->name ?? '—' }}</td><td>{{ format_number($row['attendance_days']) }}</td><td>{{ format_number($row['late_days']) }}</td><td>{{ format_number($row['late_minutes']) }}</td><td>{{ format_number($row['absence_days']) }}</td><td>{{ format_number($row['annual_leave_days']) }}</td><td>{{ format_number($row['casual_leave_days']) }}</td><td>{{ format_number($row['sick_leave_days']) }}</td><td>{{ format_number($row['unpaid_leave_days']) }}</td><td>{{ format_number($row['maternity_leave_days']) }}</td><td>{{ format_number($row['other_leave_days']) }}</td><td>{{ format_number($row['total_leave_days']) }}</td><td>{{ format_number($row['penalty_count']) }}</td><td>{{ format_number($row['penalty_days']) }}</td><td><span class="badge-soft success">{{ format_number($row['annual_balance_remaining']) }} {{ __('يوم') }}</span></td><td>{{ format_number($row['approved_request_count']) }}</td><td>{{ format_number($row['request_days']) }}</td><td>{{ format_number($row['request_hours']) }}</td><td>{{ format_number($row['request_additions']) }}</td><td>{{ format_number($row['request_deductions']) }}</td><td>{{ $row['request_types'] ?: '—' }}</td></tr>@empty<tr><td colspan="23" class="text-center py-5">{{ __('لا توجد بيانات مطابقة للفترة أو الفلاتر الحالية.') }}</td></tr>@endforelse
</tbody></table></div></div></div>
@if($employees->hasPages())
<div class="d-flex justify-content-center mt-4 summary-pagination">
    {{ $employees->links() }}
    <form method="GET" action="{{ route('jobs.index') }}" class="d-flex align-items-center gap-2 ms-3">
        @foreach(request()->except(['page', 'per_page']) as $key => $value)
            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label class="small text-muted mb-0" for="jobsPerPage">{{ __('عدد الصفوف') }}</label>
        <select id="jobsPerPage" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="{{ __('عدد الصفوف في الصفحة') }}">
            @foreach([25, 50, 100, 200] as $size)<option value="{{ $size }}" @selected((int) request('per_page', 50) === $size)>{{ $size }}</option>@endforeach
        </select>
    </form>
</div>
@endif
<div id="summaryHistory" hidden><div class="panel table-panel"><div class="panel-head"><div><h3>{{ __('سجل الملخصات المحفوظة') }}</h3><span>{{ __('كل سجل لقطة ثابتة للبيانات وقت الحفظ.') }}</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('رقم الشهر') }}</th><th>{{ __('عدد الموظفين') }}</th><th>{{ __('تاريخ الحفظ') }}</th><th>{{ __('الإجراء') }}</th></tr></thead><tbody>@forelse($summaryHistory as $snapshot)<tr><td>{{ $snapshot->summary_month->format('m/Y') }}</td><td>{{ $snapshot->employee_count }}</td><td>{{ $snapshot->created_at->format('d/m/Y H:i') }}</td><td><button type="button" class="btn btn-sm btn-outline-primary summary-history-open" data-url="{{ route('jobs.summary-history.show',$snapshot) }}"><i class="bi bi-eye"></i> {{ __('فتح') }}</button> <a class="btn btn-sm btn-outline-success" href="{{ route('jobs.summary-history.export',$snapshot) }}"><i class="bi bi-file-earmark-excel"></i> {{ __('تصدير Excel') }}</a></td></tr>@empty<tr><td colspan="4" class="text-center py-4">{{ __('لا توجد سجلات محفوظة بعد.') }}</td></tr>@endforelse</tbody></table></div></div></div>
<div class="modal fade" id="summaryHistoryModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">{{ __('سجل ملخص الشهر') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="summaryHistoryBody"></div><div class="modal-footer"><a id="summaryHistoryExport" class="btn btn-success" href="#"><i class="bi bi-file-earmark-excel"></i> {{ __('تصدير Excel') }}</a><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إغلاق') }}</button></div></div></div></div>
<link rel="stylesheet" href="{{ asset('css/pages/jobs-index-01.css') }}?v={{ filemtime(public_path('css/pages/jobs-index-01.css')) }}">
<script>
document.querySelectorAll('[data-summary-tab]').forEach(button => button.addEventListener('click', () => {
    const history = button.dataset.summaryTab === 'history';
    document.getElementById('summaryCurrent').hidden = history;
    document.getElementById('summaryHistory').hidden = !history;
    document.querySelectorAll('[data-summary-tab]').forEach(item => item.className = 'btn ' + (item === button ? 'btn-primary' : 'btn-light'));
}));
document.querySelectorAll('.summary-history-open').forEach(button => button.addEventListener('click', async () => {
    const response = await fetch(button.dataset.url, {headers:{Accept:'application/json'}});
    if (!response.ok) return;
    const data = await response.json();
    // Labels are declared before use and aligned 1:1 with the stored row keys.
    const keys = ['employee_code','employee_name','department','attendance_days','late_days','late_minutes','absence_days','early_leave_minutes','day_overtime_minutes','night_overtime_minutes','annual_leave_days','casual_leave_days','sick_leave_days','unpaid_leave_days','maternity_leave_days','other_leave_days','total_leave_days','annual_balance_remaining','penalty_count','penalty_days','advance_request_count','permission_request_count','mission_request_count'];
    const labels = [window.masarT('الكود'),window.masarT('الموظف'),window.masarT('القسم'),window.masarT('الحضور'),window.masarT('حضور متأخر'),window.masarT('دقائق التأخير'),window.masarT('الغياب'),window.masarT('انصراف مبكر (دقيقة)'),window.masarT('إضافي نهاري (دقيقة)'),window.masarT('إضافي ليلي (دقيقة)'),window.masarT('سنوية'),window.masarT('عارضة'),window.masarT('مرضية'),window.masarT('بدون أجر'),window.masarT('أمومة'),window.masarT('أخرى'),window.masarT('إجمالي الإجازات'),window.masarT('الرصيد المتبقي'),window.masarT('الجزاءات'),window.masarT('أيام الخصم'),window.masarT('سلفة'),window.masarT('إذن'),window.masarT('مأمورية')];
    document.getElementById('summaryHistoryBody').innerHTML = window.masarStaticHtml('<p class="text-muted">رقم الشهر: ')+data.month+'</p><div class="table-responsive"><table class="table table-sm monthly-summary-table"><thead><tr>'+labels.map(label => '<th>'+label+'</th>').join('')+'</tr></thead><tbody>'+data.rows.map(row => '<tr>'+keys.map(key => '<td>'+String(row[key] ?? '—')+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
    document.getElementById('summaryHistoryExport').href = data.export_url;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('summaryHistoryModal')).show();
}));
// Keep the request columns grouped with the rest of the summary even for
// cached versions of the Blade header.
const summaryTable = document.querySelector('.monthly-summary-table');
if (summaryTable) {
    const headRows = summaryTable.tHead?.rows;
    const summaryData = @json($summaryRowData);
    if (headRows?.length >= 2) {
        headRows[0].innerHTML = window.masarStaticHtml('<th colspan="3">الموظف</th><th colspan="7">الحضور والغياب</th><th colspan="8">الإجازات المؤكدة داخل الفترة (أيام)</th><th colspan="2">الجزاءات</th><th colspan="3">الطلبات المؤكدة</th>');
        headRows[1].innerHTML = [window.masarT('الكود'),window.masarT('اسم الموظف'),window.masarT('القسم'),window.masarT('أيام الحضور'),window.masarT('منها حضور متأخر'),window.masarT('دقائق التأخير'),window.masarT('أيام الغياب'),window.masarT('دقائق الانصراف المبكر'),window.masarT('إضافي نهاري'),window.masarT('إضافي ليلي'),window.masarT('سنوية'),window.masarT('عارضة'),window.masarT('مرضية'),window.masarT('بدون أجر'),window.masarT('أمومة'),window.masarT('أنواع أخرى'),window.masarT('إجمالي الإجازات'),window.masarT('رصيد الإجازة السنوية المتبقي'),window.masarT('عدد الجزاءات'),window.masarT('أيام خصم الجزاءات'),window.masarT('سلفة'),window.masarT('إذن'),window.masarT('مأمورية')].map(label => `<th>${label}</th>`).join('');
        summaryTable.tBodies?.[0]?.querySelectorAll('tr').forEach(row => {
            if (row.cells.length < 23) return;
            const code = row.cells[0]?.textContent?.trim() || '';
            const data = summaryData[String(row.dataset.employeeId || '')]
                || Object.values(summaryData).find(item => String(item.employee_code || '') === code)
                || {};
            const cells = Array.from(row.cells);
            const replacement = [...cells.slice(0, 7), ...[
                data.early_leave_minutes ?? 0, data.day_overtime_minutes ?? 0, data.night_overtime_minutes ?? 0
            ].map(value => { const cell = document.createElement('td'); cell.textContent = value; return cell; }), ...cells.slice(7, 14), cells[16], cells[14], cells[15], ...[
                data.advance_request_count ?? 0, data.permission_request_count ?? 0, data.mission_request_count ?? 0
            ].map(value => { const cell = document.createElement('td'); cell.textContent = value; return cell; })];
            row.replaceChildren(...replacement);
        });
    }
    // The leave section contains seven detail columns; keep its group header aligned.
    if (headRows?.length >= 1 && headRows[0].cells.length >= 3) headRows[0].cells[2].colSpan = 8;
    const requestLabels = [window.masarT('عدد الطلبات'),window.masarT('أيام الطلبات'),window.masarT('ساعات الطلبات'),window.masarT('إضافات'),window.masarT('خصومات'),window.masarT('أنواع الطلبات')];
    if (headRows?.length >= 2 && headRows[1].cells.length < 23) {
        requestLabels.forEach(label => { const cell = document.createElement('th'); cell.textContent = label; headRows[1].append(cell); });
        const group = document.createElement('th'); group.colSpan = requestLabels.length; group.textContent = window.masarT('الطلبات المؤكدة');
        headRows[0].append(group);
    }
}
const serverSearchForm = document.querySelector('form[data-server-live-search="true"]');
const serverSearchInput = serverSearchForm?.querySelector('input[name="search"]');
let serverSearchTimer;
serverSearchInput?.addEventListener('input', () => {
    clearTimeout(serverSearchTimer);
    serverSearchTimer = setTimeout(() => serverSearchForm?.requestSubmit(), 350);
});
</script>
@endsection


@push('scripts')
<script>
// A read on focus keeps a summary left open in another tab current, without background polling.
(() => {
  let refreshing = false;
  const refresh = async () => {
    if (refreshing || document.hidden || document.getElementById('summaryCurrent')?.hidden) return;
    refreshing = true;
    try {
      const url = new URL(@json(route('jobs.rows')), location.origin);
      url.search = location.search;
      const response = await fetch(url, {headers: {Accept: 'application/json'}});
      if (!response.ok) return;
      const data = await response.json();
      const body = document.querySelector('.monthly-summary-table tbody');
      if (body && typeof data.html === 'string') body.innerHTML = data.html;
    } finally { refreshing = false; }
  };
  window.addEventListener('focus', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
</script>
@endpush
