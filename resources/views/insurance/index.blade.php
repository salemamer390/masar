@extends('layouts.app')
@section('title', 'التأمينات')
@section('content')
<div class="page-head"><div><h1>التأمينات الاجتماعية</h1><p>إدارة المؤمن عليهم وتجهيز نماذج التأمينات.</p></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" target="_blank" href="{{ route('insurance.forms.s1.print-all') }}"><i class="bi bi-printer"></i> طباعة جميع س1</a><a class="btn btn-outline-secondary" target="_blank" href="{{ route('insurance.forms.s2.editable') }}"><i class="bi bi-file-earmark-text"></i> س2</a><button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#organizationModal"><i class="bi bi-building"></i> بيانات المنشأة</button></div></div>
<div class="stat-grid compact">@foreach([['إجمالي الموظفين',$totalEmployees,'blue','people'],['مسجلون',$registered,'green','shield-check'],['قيد التسجيل',$pending,'orange','hourglass-split'],['غير مسجلين',$unregistered,'red','shield-x'],['متأخرون عن التسجيل',$overdue,'purple','exclamation-triangle']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ format_number($stat[1]) }}</b><small>حسب البيانات الحالية</small></div></div>@endforeach</div>
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET"><input type="hidden" name="tab" value="{{ $activeTab }}"><div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="بحث بالاسم أو الكود"></div><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><select name="status" class="form-select"><option value="">كل الحالات</option>@foreach(['مسجل','قيد التسجيل','غير مسجل'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select><input name="office" class="form-control" value="{{ request('office') }}" placeholder="مكتب التأمينات"><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('insurance.index') }}">مسح</a></form>
<div class="d-flex gap-2 flex-wrap mb-3 insurance-tabs" role="tablist" aria-label="أقسام التأمينات">
<a class="{{ $activeTab === 'registered' ? 'btn btn-primary' : 'btn btn-light' }}" href="{{ route('insurance.index', ['tab' => 'registered']) }}" role="tab" aria-selected="{{ $activeTab === 'registered' ? 'true' : 'false' }}">المؤمَّن عليهم <span class="badge text-bg-light">{{ $registeredRecords->count() }}</span></a>
<a class="{{ $activeTab === 'unregistered' ? 'btn btn-primary' : 'btn btn-light' }}" href="{{ route('insurance.index', ['tab' => 'unregistered']) }}" role="tab" aria-selected="{{ $activeTab === 'unregistered' ? 'true' : 'false' }}">غير مؤمن عليهم <span class="badge text-bg-light">{{ $unregisteredRecords->count() + $employees->count() }}</span></a>
</div>
@php($visibleRecords = $activeTab === 'registered' ? $registeredRecords : $unregisteredRecords)
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الموظف</th><th>القسم</th><th>الرقم التأميني</th><th>مكتب التأمينات</th><th>كود المهنة</th><th>بداية التأمين</th><th>الأجر التأميني</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
@foreach($visibleRecords as $record)<tr><td><a class="employee-link fw-bold" href="{{ route('employees.show',$record->employee) }}">{{ $record->employee?->full_name }}</a><small class="d-block text-muted">#{{ $record->employee?->employee_code }}</small></td><td>{{ $record->employee?->department?->name ?? '—' }}</td><td>{{ $record->insurance_number ?? '—' }}</td><td>{{ $record->insurance_office ?? $organization?->office_name ?? '—' }}</td><td>{{ $record->job_code ?? '—' }}</td><td>{{ $record->insurance_start_date?->format('d/m/Y') ?? '—' }}</td>@php($salary=$record->employee?->currentSalary)<td>{{ $salary && !$salary->no_insurance ? format_money($salary->applied_insurance_wage).' ج.م' : 'بدون تأمين' }}</td><td><span class="badge-soft {{ $record->status_badge_class }}">{{ $record->status }}</span></td><td><div class="d-flex gap-1 flex-wrap"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editInsurance{{ $record->id }}"><i class="bi bi-pencil"></i> تعديل</button><a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('insurance.form',$record) }}"><i class="bi bi-file-earmark-text"></i> س1</a><a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('employees.forms.s6.editable',$record->employee) }}"><i class="bi bi-file-earmark-text"></i> س6</a></div></td></tr>@endforeach
@if($activeTab === 'unregistered')@foreach($employees as $employee)<tr><td><a class="employee-link fw-bold" href="{{ route('employees.show',$employee) }}">{{ $employee->full_name }}</a><small class="d-block text-muted">#{{ $employee->employee_code }}</small></td><td>{{ $employee->department?->name ?? '—' }}</td><td>—</td><td>{{ $organization?->office_name ?? '—' }}</td><td>—</td><td>—</td>@php($salary=$employee->currentSalary)<td>{{ $salary && !$salary->no_insurance ? format_money($salary->applied_insurance_wage).' ج.م' : 'بدون تأمين' }}</td><td><span class="badge-soft badge-soft-warning">غير مسجل</span></td><td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#insuranceModal"><i class="bi bi-plus-lg"></i> تسجيل</button><a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('employees.forms.s1.editable',$employee) }}"><i class="bi bi-file-earmark-text"></i> س1</a></td></tr>@endforeach @endif
@if($visibleRecords->isEmpty() && ($activeTab !== 'unregistered' || $employees->isEmpty()))<tr><td colspan="9" class="text-center py-5">لا توجد سجلات تأمين بهذه المعايير.</td></tr>@endif
</tbody></table></div></div>

<div class="modal fade" id="organizationModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST" action="{{ route('insurance.organization.update') }}">@csrf @method('PUT')<div class="modal-header"><h5>بيانات المنشأة ومقدم الطلب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3">
    <div class="col-md-6"><label class="form-label">مكتب</label><input name="office_name" class="form-control" value="{{ $organization?->office_name }}"></div>
    <div class="col-md-6"><label class="form-label">مقدم الطلب</label><input name="applicant_name" class="form-control" value="{{ $organization?->applicant_name }}"></div>
    <div class="col-md-6"><label class="form-label">صفة مقدم الطلب</label><input name="applicant_title" class="form-control" value="{{ $organization?->applicant_title }}"></div>
    <div class="col-md-6"><label class="form-label">الرقم التأميني لمقدم الطلب</label><input name="applicant_insurance_number" class="form-control" value="{{ $organization?->applicant_insurance_number }}"></div>
    <div class="col-md-6"><label class="form-label">الرقم القومي لمقدم الطلب</label><input name="applicant_national_id" maxlength="14" class="form-control" value="{{ $organization?->applicant_national_id }}"></div>
    <div class="col-md-6"><label class="form-label">رقم التليفون</label><input name="phone" class="form-control" value="{{ $organization?->phone }}"></div>
    <div class="col-md-6"><label class="form-label">اسم المنشأة</label><input name="organization_name" class="form-control" value="{{ $organization?->organization_name }}"></div>
    <div class="col-md-6"><label class="form-label">قطاع المنشأة</label><input name="sector" class="form-control" value="{{ $organization?->sector }}"></div>
    <div class="col-md-6"><label class="form-label">رقم التسجيل الضريبي</label><input name="tax_registration_number" class="form-control" value="{{ $organization?->tax_registration_number }}"></div>
    <div class="col-md-6"><label class="form-label">رقم السجل التجاري</label><input name="commercial_registration_number" class="form-control" value="{{ $organization?->commercial_registration_number }}"></div>
    <div class="col-md-6"><label class="form-label">رقم السجل التجاري الموحد</label><input name="unified_commercial_registration_number" class="form-control" value="{{ $organization?->unified_commercial_registration_number }}"></div>
    <div class="col-md-6"><label class="form-label">رقم المنشأة</label><input name="establishment_number" class="form-control" value="{{ $organization?->establishment_number }}"></div>
    <div class="col-12"><label class="form-label">العنوان</label><input name="address" class="form-control" value="{{ $organization?->address }}"></div>
    <div class="col-md-4"><label class="form-label">مدة السماح للتسجيل (يوم)</label><input type="number" min="1" max="365" name="grace_days" class="form-control" value="{{ $organization?->grace_days ?? 30 }}" required></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ البيانات</button></div></form></div></div></div>

<div class="modal fade" id="insuranceModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST" action="{{ route('insurance.store') }}">@csrf<div class="modal-header"><h5>تسجيل موظف بالتأمينات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><div class="col-md-6"><label class="form-label">الموظف</label><select name="employee_id" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">الفئة التأمينية</label><input name="insurance_category" class="form-control" value="العاملون لدى الغير"></div><div class="col-md-4"><label class="form-label">الرقم التأميني</label><input name="insurance_number" class="form-control"></div><div class="col-md-4"><label class="form-label">مكتب التأمينات</label><input name="insurance_office" class="form-control" value="{{ $organization?->office_name }}"></div><div class="col-md-4"><label class="form-label">كود المهنة</label><input name="job_code" class="form-control"></div><div class="col-md-4"><label class="form-label">تاريخ البداية</label><input name="insurance_start_date" type="date" class="form-control"></div><div class="col-md-4"><label class="form-label">آخر موعد للتسجيل</label><input name="registration_deadline" type="date" class="form-control"></div><div class="col-md-4"><label class="form-label">الحالة</label><select name="status" class="form-select"><option>مسجل</option><option>قيد التسجيل</option><option>غير مسجل</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ السجل</button></div></form></div></div></div>
@foreach($insuranceRecords as $record)<div class="modal fade" id="editInsurance{{ $record->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST" action="{{ route('insurance.update',$record) }}">@csrf @method('PUT')<div class="modal-header"><h5>تعديل بيانات تأمين {{ $record->employee?->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><div class="col-md-4"><label class="form-label">الرقم التأميني</label><input name="insurance_number" class="form-control" value="{{ $record->insurance_number }}"></div><div class="col-md-4"><label class="form-label">مكتب التأمينات</label><input name="insurance_office" class="form-control" value="{{ $record->insurance_office }}"></div><div class="col-md-4"><label class="form-label">كود المهنة</label><input name="job_code" class="form-control" value="{{ $record->job_code }}"></div><div class="col-md-4"><label class="form-label">الفئة</label><input name="insurance_category" class="form-control" value="{{ $record->insurance_category }}"></div><div class="col-md-4"><label class="form-label">تاريخ البداية</label><input name="insurance_start_date" type="date" class="form-control" value="{{ $record->insurance_start_date?->format('Y-m-d') }}"></div><div class="col-md-4"><label class="form-label">تاريخ النهاية</label><input name="insurance_end_date" type="date" class="form-control" value="{{ $record->insurance_end_date?->format('Y-m-d') }}"></div><div class="col-md-4"><label class="form-label">موعد التسجيل</label><input name="registration_deadline" type="date" class="form-control" value="{{ $record->registration_deadline?->format('Y-m-d') }}"></div><div class="col-md-4"><label class="form-label">الحالة</label><select name="status" class="form-select">@foreach(['مسجل','قيد التسجيل','غير مسجل'] as $status)<option @selected($record->status===$status)>{{ $status }}</option>@endforeach</select></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>@endforeach
<script>
document.querySelectorAll('input[name="insurance_category"]').forEach(function (input) {
    const current = input.value || '\u0627\u0644\0639\0627\0645\0644\0648\0646 \u0644\062f\u0649 \u0627\0644\063a\064a\u0631';
    const select = document.createElement('select');
    select.name = input.name;
    select.className = input.className.replace('form-control', 'form-select');
    select.required = true;
    [
        ['\u0627\u0644\0639\0627\0645\0644\0648\0646 \u0644\062f\0649 \u0627\u0644\063a\064a\0631', '\u0627\u0644\0639\0627\0645\0644\u0648\0646 \u0644\062f\0649 \u0627\u0644\063a\064a\0631'],
        ['\u0623\u0635\062d\0627\0628 \u0623\0639\u0645\u0627\0644 \u0644\u0647\u0645 \u0645\0646\u0634\u0622\u062a', '\u0623\u0635\062d\u0627\u0628 \u0623\u0639\u0645\u0627\u0644 \u0644\u0647\u0645 \u0645\0646\u0634\u0622\u062a'],
        ['\u0627\u0644\0639\0627\u0645\0644\064a\0646 \u0628\u0627\u0644\u0645\062e\0627\0628\0632', '\u0627\u0644\0639\u0627\0645\u0644\064a\0646 \u0628\u0627\u0644\u0645\062e\u0627\u0628\0632']
    ].forEach(function (item) {
        const option = new Option(item[1], item[0]);
        option.selected = item[0] === current;
        select.add(option);
    });
    select.dataset.currentCategory = current;
    input.replaceWith(select);
});
</script>
<script>
document.querySelectorAll('select[name="insurance_category"]').forEach(function (select) {
    const selected = select.dataset.currentCategory || select.value;
    select.innerHTML = '';
    [
        ['workers', '&#x627;&#x644;&#x639;&#x627;&#x645;&#x644;&#x648;&#x646; &#x644;&#x62F;&#x649; &#x627;&#x644;&#x63A;&#x64A;&#x631;'],
        ['owners', '&#x623;&#x635;&#x62D;&#x627;&#x628; &#x623;&#x639;&#x645;&#x627;&#x644; &#x644;&#x647;&#x645; &#x645;&#x646;&#x634;&#x622;&#x62A;'],
        ['bakeries', '&#x627;&#x644;&#x639;&#x627;&#x645;&#x644;&#x64A;&#x646; &#x628;&#x627;&#x644;&#x645;&#x62E;&#x627;&#x628;&#x632;']
    ].forEach(function (item) {
        const option = document.createElement('option');
        option.value = item[0];
        option.innerHTML = item[1];
        option.selected = item[0] === selected;
        select.appendChild(option);
    });
    if (!select.value) select.value = 'workers';
});
</script>
<script>
document.querySelectorAll('select[name="status"]').forEach(function (select) {
    const current = select.value;
    select.innerHTML = '';
    [
        ['registered', '&#x645;&#x633;&#x62C;&#x644;'],
        ['unregistered', '&#x63A;&#x64A;&#x631; &#x645;&#x633;&#x62C;&#x644;']
    ].forEach(function (item) {
        const option = document.createElement('option');
        option.value = item[0];
        option.innerHTML = item[1];
        option.selected = current === option.value || (item[0] === 'registered' && !current);
        select.appendChild(option);
    });
    if (!select.value) select.selectedIndex = 0;
});
</script>
<script>
document.querySelectorAll('.table-panel tbody tr td:nth-child(8) span.badge-soft').forEach(function (badge) {
    // Status changes are handled by the unified AJAX handler below.
    return;
    const row = badge.closest('tr');
    const editButton = row?.querySelector('[data-bs-target^="#editInsurance"]');
    const editForm = editButton ? document.querySelector(editButton.dataset.bsTarget + ' form') : null;
    if (!editForm) return;
    const select = document.createElement('select');
    select.className = badge.className.replace('badge-soft', 'badge-soft border-0');
    select.style.cssText = 'cursor:pointer;appearance:auto;';
    [['registered', '&#x645;&#x633;&#x62C;&#x644;'], ['unregistered', '&#x63A;&#x64A;&#x631; &#x645;&#x633;&#x62C;&#x644;']].forEach(function (item) {
        const option = document.createElement('option');
        option.value = item[0];
        option.innerHTML = item[1];
        option.selected = badge.textContent.trim() === item[0] || badge.textContent.trim() === option.textContent;
        select.appendChild(option);
    });
    select.addEventListener('change', function () {
        const status = editForm.querySelector('[name="status"]');
        if (!status) return;
        status.value = select.value;
        select.disabled = true;
        fetch(editForm.action, {
            method: 'POST',
            body: new FormData(editForm),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('status update failed');
            const badgeClass = select.value === 'registered' ? 'success' : 'danger';
            badge.className = 'badge-soft ' + badgeClass;
            select.className = 'badge-soft border-0 ' + badgeClass;
            select.disabled = false;
            if (typeof showToast === 'function') showToast('Insurance record updated.');
        }).catch(function () {
            select.disabled = false;
            if (typeof showToast === 'function') showToast('تعذر تحديث الحالة، حاول مرة أخرى.');
        });
    });
    badge.replaceWith(select);
});
</script>
<script>
(function () {
    const organizationForm = document.querySelector('#organizationModal form');
    if (!organizationForm) return;
    organizationForm.dataset.ajax = 'true';
    organizationForm.dataset.ajaxLabel = 'جارٍ الحفظ';
    document.addEventListener('masar:success', function (event) {
        if (event.target !== organizationForm) return;
        bootstrap.Modal.getInstance(document.getElementById('organizationModal'))?.hide();
    });
})();
</script>
<script>
(function () {
    const recordForms = () => [
        ...document.querySelectorAll('#insuranceModal form, [id^="editInsurance"] form')
    ];
    const bindRecordForms = () => recordForms().forEach(form => {
        form.dataset.ajax = 'true';
        form.dataset.ajaxLabel = 'جارٍ الحفظ';
    });
    const bindStatusSelects = (root = document) => {
        // The legacy inline handler may already have converted a badge to a
        // select. Bind only the server-rendered badge spans so we never wrap
        // an existing select again (which could reset "غير مسجل" to the first option).
        root.querySelectorAll('.table-panel tbody tr td:nth-child(8) span.badge-soft').forEach(badge => {
            if (badge.dataset.statusBound === 'true') return;
            const row = badge.closest('tr');
            const editButton = row?.querySelector('[data-bs-target^="#editInsurance"]');
            const editForm = editButton ? document.querySelector(editButton.dataset.bsTarget + ' form') : null;
            if (!editForm) return;
            const current = badge.textContent.trim();
            const currentValue = current === 'unregistered' || current.includes('غير مسجل')
                ? 'unregistered'
                : 'registered';
            const select = document.createElement('select');
            select.className = badge.className + ' border-0';
            select.style.cssText = 'cursor:pointer;appearance:auto;';
            [['registered', 'مسجل'], ['unregistered', 'غير مسجل']].forEach(item => {
                const option = new Option(item[1], item[0], currentValue === item[0], currentValue === item[0]);
                select.add(option);
            });
            select.addEventListener('change', async () => {
                const status = editForm.querySelector('[name="status"]');
                if (!status) return;
                status.value = select.value;
                select.disabled = true;
                const row = select.closest('tr');
                row?.classList.add('insurance-row-updating');
                try {
                    const response = await fetch(editForm.action, {
                        method: 'POST',
                        body: new FormData(editForm),
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const error = new Error(payload.message || 'Insurance status update failed.');
                        error.payload = payload;
                        throw error;
                    }
                    if (row) {
                        row.style.transition = 'opacity .28s ease, transform .28s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-18px)';
                        window.setTimeout(() => row.remove(), 300);
                    }
                    scheduleInsuranceStatsRefresh();
                    select.className = 'badge-soft border-0 ' + (select.value === 'registered' ? 'success' : 'danger');
                    showToast('تم تحديث حالة التأمين بنجاح.');
                } catch (error) {
                    showToast(error.payload?.message || 'تعذر تحديث حالة التأمين.');
                } finally { select.disabled = false; }
            });
            badge.replaceWith(select);
        });
    };
    let statsRefreshTimer;
    const scheduleInsuranceStatsRefresh = () => {
        window.clearTimeout(statsRefreshTimer);
        statsRefreshTimer = window.setTimeout(async () => {
            try {
                const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) return;
                const source = new DOMParser().parseFromString(await response.text(), 'text/html');
                const currentStats = document.querySelector('.stat-grid.compact');
                const freshStats = source.querySelector('.stat-grid.compact');
                if (currentStats && freshStats) currentStats.replaceWith(freshStats);
            } catch (error) { console.error('Insurance stats refresh failed', error); }
        }, 350);
    };
    const refreshInsuranceList = async () => {
        const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const source = new DOMParser().parseFromString(await response.text(), 'text/html');
        const currentTable = document.querySelector('.panel.table-panel');
        const freshTable = source.querySelector('.panel.table-panel');
        if (currentTable && freshTable) currentTable.replaceWith(freshTable);
        const currentStats = document.querySelector('.stat-grid.compact');
        const freshStats = source.querySelector('.stat-grid.compact');
        if (currentStats && freshStats) currentStats.replaceWith(freshStats);
        source.querySelectorAll('#insuranceModal, [id^="editInsurance"]').forEach(freshModal => {
            const currentModal = document.getElementById(freshModal.id);
            if (currentModal) currentModal.replaceWith(freshModal.cloneNode(true));
            else document.body.appendChild(freshModal.cloneNode(true));
        });
        bindRecordForms();
        bindStatusSelects();
    };
    bindRecordForms();
    bindStatusSelects();
    document.addEventListener('masar:success', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !recordForms().includes(form)) return;
        bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
        try { await refreshInsuranceList(); } catch (error) { console.error('Insurance list refresh failed', error); }
    });
})();
</script>
<script>
document.querySelectorAll('.table-panel [data-bs-target="#insuranceModal"]').forEach(button => button.remove());
</script>
@endsection
