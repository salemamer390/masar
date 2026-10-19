@extends('layouts.app')
@section('title', 'ملخص الشهر')
@section('content')
<div class="page-head"><div><h1>ملخص الشهر</h1><p>كشف الحضور والإجازات والجزاءات والأرصدة خلال الفترة المحددة.</p></div><span class="badge-soft info px-3 py-2"><i class="bi bi-calendar3"></i> {{ $month->format('m/Y') }}</span></div>
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET" data-server-live-search="true"><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"><div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="بحث بالاسم أو الكود"></div><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('jobs.index') }}">مسح</a></form>
<div class="d-flex gap-2 flex-wrap mb-3"><button class="btn btn-primary" type="button" data-summary-tab="current"><i class="bi bi-table"></i> ملخص الشهر الحالي</button><button class="btn btn-light" type="button" data-summary-tab="history"><i class="bi bi-clock-history"></i> سجل الملخص</button><a class="btn btn-outline-success" href="{{ route('jobs.export', ['month' => $month->format('Y-m')]) }}"><i class="bi bi-file-earmark-excel"></i> تصدير Excel</a><form method="POST" action="{{ route('jobs.summary-history.store') }}" class="d-inline">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><button class="btn btn-outline-primary"><i class="bi bi-save"></i> حفظ في السجل</button></form></div>
<div id="summaryCurrent"><div class="panel table-panel"><div class="panel-head"><div><h3>ملخص حركة الموظفين</h3><span>السجلات المعتمدة داخل الفترة المحددة.</span></div></div><div class="table-responsive"><table class="table align-middle monthly-summary-table"><thead><tr><th colspan="3">الموظف</th><th colspan="4">الحضور والغياب</th><th colspan="6">الإجازات المعتمدة داخل الفترة (أيام)</th><th colspan="2">الجزاءات</th><th>الرصيد</th><th colspan="6">الطلبات المعتمدة</th></tr><tr><th>الكود</th><th>اسم الموظف</th><th>القسم</th><th>أيام الحضور</th><th>منها حضور متأخر</th><th>دقائق التأخير</th><th>أيام الغياب</th><th>سنوية</th><th>عارضة</th><th>مرضية</th><th>بدون أجر</th><th>أمومة</th><th>أنواع أخرى</th><th>إجمالي الإجازات</th><th>عدد الجزاءات</th><th>أيام خصم الجزاءات</th><th>رصيد الإجازة السنوية المتبقي</th><th>عدد الطلبات</th><th>أيام الطلبات</th><th>ساعات الطلبات</th><th>إضافات</th><th>خصومات</th><th>أنواع الطلبات</th></thead><tbody>
@forelse($monthlyRows as $row) @php($employee=$row['employee'])<tr><td>{{ $employee->employee_code }}</td><td><a class="employee-link fw-bold" href="{{ route('employees.show',$employee) }}">{{ $employee->full_name }}</a></td><td>{{ $employee->department?->name ?? '—' }}</td><td>{{ format_number($row['attendance_days']) }}</td><td>{{ format_number($row['late_days']) }}</td><td>{{ format_number($row['late_minutes']) }}</td><td>{{ format_number($row['absence_days']) }}</td><td>{{ format_number($row['annual_leave_days']) }}</td><td>{{ format_number($row['casual_leave_days']) }}</td><td>{{ format_number($row['sick_leave_days']) }}</td><td>{{ format_number($row['unpaid_leave_days']) }}</td><td>{{ format_number($row['maternity_leave_days']) }}</td><td>{{ format_number($row['other_leave_days']) }}</td><td>{{ format_number($row['total_leave_days']) }}</td><td>{{ format_number($row['penalty_count']) }}</td><td>{{ format_number($row['penalty_days']) }}</td><td><span class="badge-soft success">{{ format_number($row['annual_balance_remaining']) }} يوم</span></td><td>{{ format_number($row['approved_request_count']) }}</td><td>{{ format_number($row['request_days']) }}</td><td>{{ format_number($row['request_hours']) }}</td><td>{{ format_number($row['request_additions']) }}</td><td>{{ format_number($row['request_deductions']) }}</td><td>{{ $row['request_types'] ?: '—' }}</td></tr>@empty<tr><td colspan="23" class="text-center py-5">لا توجد بيانات مطابقة للفترة أو الفلاتر الحالية.</td></tr>@endforelse
</tbody></table></div></div></div>
@if($employees->hasPages())
<div class="d-flex justify-content-center mt-4 summary-pagination">
    {{ $employees->links() }}
    <form method="GET" action="{{ route('jobs.index') }}" class="d-flex align-items-center gap-2 ms-3">
        @foreach(request()->except(['page', 'per_page']) as $key => $value)
            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label class="small text-muted mb-0" for="jobsPerPage">عدد الصفوف</label>
        <select id="jobsPerPage" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="عدد الصفوف في الصفحة">
            @foreach([25, 50, 100, 200] as $size)<option value="{{ $size }}" @selected((int) request('per_page', 50) === $size)>{{ $size }}</option>@endforeach
        </select>
    </form>
</div>
@endif
<div id="summaryHistory" hidden><div class="panel table-panel"><div class="panel-head"><div><h3>سجل الملخصات المحفوظة</h3><span>كل سجل لقطة ثابتة للبيانات وقت الحفظ.</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>رقم الشهر</th><th>عدد الموظفين</th><th>تاريخ الحفظ</th><th>الإجراء</th></tr></thead><tbody>@forelse($summaryHistory as $snapshot)<tr><td>{{ $snapshot->summary_month->format('m/Y') }}</td><td>{{ $snapshot->employee_count }}</td><td>{{ $snapshot->created_at->format('d/m/Y H:i') }}</td><td><button type="button" class="btn btn-sm btn-outline-primary summary-history-open" data-url="{{ route('jobs.summary-history.show',$snapshot) }}"><i class="bi bi-eye"></i> فتح</button> <a class="btn btn-sm btn-outline-success" href="{{ route('jobs.summary-history.export',$snapshot) }}"><i class="bi bi-file-earmark-excel"></i> تصدير Excel</a></td></tr>@empty<tr><td colspan="4" class="text-center py-4">لا توجد سجلات محفوظة بعد.</td></tr>@endforelse</tbody></table></div></div></div>
<div class="modal fade" id="summaryHistoryModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">سجل ملخص الشهر</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="summaryHistoryBody"></div><div class="modal-footer"><a id="summaryHistoryExport" class="btn btn-success" href="#"><i class="bi bi-file-earmark-excel"></i> تصدير Excel</a><button type="button" class="btn btn-light" data-bs-dismiss="modal">إغلاق</button></div></div></div></div>
<style>
.filter-bar + .d-flex form[action*="summary-history"] { display: none !important; }
.monthly-summary-table { --summary-blue:#eaf3ff; --summary-orange:#fff4df; --summary-green:#eafaf1; --summary-red:#fff0f1; --summary-purple:#f3edff; --summary-cyan:#e8f7ff; }
.monthly-summary-table thead tr:first-child th { font-weight:800; border-bottom:0; }
.monthly-summary-table thead tr:first-child th:nth-child(1), .monthly-summary-table thead tr:nth-child(2) th:nth-child(-n+3), .monthly-summary-table tbody td:nth-child(-n+3) { background:var(--summary-blue); }
.monthly-summary-table thead tr:first-child th:nth-child(2), .monthly-summary-table thead tr:nth-child(2) th:nth-child(n+4):nth-child(-n+7), .monthly-summary-table tbody td:nth-child(n+4):nth-child(-n+7) { background:var(--summary-orange); }
.monthly-summary-table thead tr:first-child th:nth-child(3), .monthly-summary-table thead tr:nth-child(2) th:nth-child(n+8):nth-child(-n+14), .monthly-summary-table tbody td:nth-child(n+8):nth-child(-n+14) { background:var(--summary-green); }
.monthly-summary-table thead tr:first-child th:nth-child(4), .monthly-summary-table thead tr:nth-child(2) th:nth-child(n+15):nth-child(-n+16), .monthly-summary-table tbody td:nth-child(n+15):nth-child(-n+16) { background:var(--summary-red); }
.monthly-summary-table thead tr:first-child th:nth-child(5), .monthly-summary-table thead tr:nth-child(2) th:nth-child(17), .monthly-summary-table tbody td:nth-child(17) { background:var(--summary-purple); }
.monthly-summary-table thead tr:first-child th:nth-child(6), .monthly-summary-table thead tr:nth-child(2) th:nth-child(n+18):nth-child(-n+23), .monthly-summary-table tbody td:nth-child(n+18):nth-child(-n+23) { background:var(--summary-cyan); }
.monthly-summary-table tbody td { border-color:rgba(30,50,80,.08); }
.monthly-summary-table thead th, .monthly-summary-table tbody td { text-align:center; vertical-align:middle; white-space:nowrap; }
.monthly-summary-table { min-width:1900px; }
</style>
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
    if (labels.length === 17) labels.push('عدد الطلبات','أيام الطلبات','ساعات الطلبات','إضافات','خصومات','أنواع الطلبات');
    const keys = ['employee_code','employee_name','department','attendance_days','late_days','late_minutes','absence_days','annual_leave_days','casual_leave_days','sick_leave_days','unpaid_leave_days','maternity_leave_days','other_leave_days','total_leave_days','penalty_count','penalty_days','approved_request_count','request_days','request_hours','request_additions','request_deductions','request_types','annual_balance_remaining'];
    const labels = ['الكود','الموظف','القسم','الحضور','حضور متأخر','دقائق التأخير','الغياب','سنوية','عارضة','مرضية','بدون أجر','أمومة','أخرى','إجمالي الإجازات','الجزاءات','أيام الخصم','الرصيد المتبقي'];
    document.getElementById('summaryHistoryBody').innerHTML = '<p class="text-muted">رقم الشهر: '+data.month+'</p><div class="table-responsive"><table class="table table-sm monthly-summary-table"><thead><tr>'+labels.map(label => '<th>'+label+'</th>').join('')+'</tr></thead><tbody>'+data.rows.map(row => '<tr>'+keys.map(key => '<td>'+String(row[key] ?? '—')+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
    document.getElementById('summaryHistoryExport').href = data.export_url;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('summaryHistoryModal')).show();
}));
// Keep the request columns grouped with the rest of the summary even for
// cached versions of the Blade header.
const summaryTable = document.querySelector('.monthly-summary-table');
if (summaryTable) {
    const headRows = summaryTable.tHead?.rows;
    // The leave section contains seven detail columns; keep its group header aligned.
    if (headRows?.length >= 1 && headRows[0].cells.length >= 3) headRows[0].cells[2].colSpan = 7;
    const requestLabels = ['عدد الطلبات','أيام الطلبات','ساعات الطلبات','إضافات','خصومات','أنواع الطلبات'];
    if (headRows?.length >= 2 && headRows[1].cells.length < 23) {
        requestLabels.forEach(label => { const cell = document.createElement('th'); cell.textContent = label; headRows[1].append(cell); });
        const group = document.createElement('th'); group.colSpan = requestLabels.length; group.textContent = 'الطلبات المعتمدة';
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

