@extends('layouts.app')
@section('title', 'الطلبات')

@section('content')
<div class="page-head">
    <div>
        <h1>طلبات الموارد البشرية</h1>
        <p>طلبات السلف والأذونات والمأموريات وطلبات المستندات والطلبات الأخرى، مرتبطة مباشرة بقاعدة البيانات.</p>
    </div>
    @if(auth()->user()?->hasPermission('requests.create'))
        <a href="{{ route('requests.index', ['create' => 1]) }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> طلب جديد</a>
    @endif
</div>

<div class="modal fade" id="requestCardDetails" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="requestCardTitle">تفاصيل الطلبات</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="requestCardBody" class="employee-kpi-detail-list"></div></div></div></div></div>

<div class="stat-grid compact">
    @foreach([
        ['إجمالي الطلبات', $stats['all'], 'blue', 'file-earmark-text'],
        ['طلبات معلقة', $stats['pending'], 'orange', 'hourglass-split'],
        ['طلبات معتمدة', $stats['approved'], 'green', 'check-circle'],
        ['طلبات مرفوضة', $stats['rejected'], 'red', 'x-circle'],
    ] as $statIndex => $stat)
        <div class="stat-card clickable-card request-kpi-card" data-request-kpi="{{ $statIndex }}">
            <div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div>
            <div><span>{{ $stat[0] }}</span><b>{{ format_number($stat[1]) }}</b><small>حسب النطاق المسموح لك</small></div>
        </div>
    @endforeach
</div>

<form class="filter-bar" method="GET">
    <div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="ابحث بالموظف أو عنوان الطلب..."></div>
    <select name="type" onchange="this.form.submit()">
        <option value="">كل الأنواع</option>
        @foreach($requestTypes as $type)
            <option value="{{ $type->code }}" @selected(request('type') === $type->code)>{{ $type->display_name }}</option>
        @endforeach
    </select>
    <select name="status" onchange="this.form.submit()">
        <option value="">كل الحالات</option>
        @foreach(['Pending'=>'معلق','Manager Approved'=>'بانتظار HR','Approved'=>'معتمد','Rejected'=>'مرفوض'] as $value => $label)
            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">بحث</button>
    @if(request()->hasAny(['search','type','status']))
        <a class="btn btn-outline-secondary" href="{{ route('requests.index') }}">مسح</a>
    @endif
</form>

@if($selectedRequest)
<div class="panel mb-3">
    <div class="panel-head">
        <div>
            <h3><i class="bi {{ $selectedRequest->requestType?->icon ?? 'bi-file-earmark-text' }}"></i> تفاصيل الطلب</h3>
            <span>{{ $selectedRequest->requestType?->display_name ?? $selectedRequest->request_type }}</span>
        </div>
        <a class="btn btn-sm btn-light" href="{{ route('requests.index', request()->except('request')) }}">إغلاق</a>
    </div>
    <div class="details-grid p-3">
        <div><label>الموظف</label><b>{{ $selectedRequest->employee?->full_name ?? '—' }}</b></div>
        <div><label>القسم</label><b>{{ $selectedRequest->employee?->department?->name ?? '—' }}</b></div>
        <div><label>نوع الطلب</label><b>{{ $selectedRequest->requestType?->display_name ?? $selectedRequest->request_type }}</b></div>
        <div><label>الحالة</label><b><span class="badge-soft {{ $selectedRequest->status_badge_class }}">{{ $selectedRequest->status_label }}</span></b></div>
        <div><label>العنوان</label><b>{{ $selectedRequest->title }}</b></div>
        <div><label>تاريخ الإنشاء</label><b>{{ $selectedRequest->created_at?->format('d/m/Y H:i') ?? '—' }}</b></div>
        <div class="details-grid-full"><label>التفاصيل</label><p class="mb-0">{{ $selectedRequest->details ?: 'لا توجد تفاصيل إضافية.' }}</p></div>
        @if($selectedRequest->rejection_reason)
            <div class="details-grid-full"><label>سبب الرفض</label><p class="mb-0 text-danger">{{ $selectedRequest->rejection_reason }}</p></div>
        @endif
    </div>
    @if($selectedRequest->status === 'Pending' || $selectedRequest->status === 'Manager Approved')
        @if(auth()->user()?->hasPermission('requests.approve'))
            <div class="d-flex gap-2 px-3 pb-3">
                <form method="POST" action="{{ route('requests.approve', $selectedRequest) }}">@csrf @method('PATCH')<button class="btn btn-success"><i class="bi bi-check-lg"></i> اعتماد</button></form>
                <form method="POST" action="{{ route('requests.reject', $selectedRequest) }}" class="d-flex gap-2 flex-grow-1"><input type="text" name="rejection_reason" class="form-control" placeholder="سبب الرفض (اختياري)">@csrf @method('PATCH')<button class="btn btn-outline-danger">رفض</button></form>
            </div>
        @endif
    @endif
</div>
@endif

@push('scripts')
<script>
// Normalize dynamically rendered delete forms so they always use the current
// application's base URL and the CSRF token emitted by Laravel.
document.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
  const form = methodInput.form;
  if (!form) return;
  const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = `${new URL('/requests/' + id, window.location.origin).pathname}`;
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const tokenInput = form.querySelector('input[name="_token"]');
  if (token && tokenInput) tokenInput.value = token;
});
</script>
@endpush

@push('scripts')
<script>
// Re-apply CSRF and URL normalization after the table script has replaced
// action cells with edit/delete controls.
document.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
  const form = methodInput.form;
  if (!form) return;
  const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = new URL('/requests/' + id, window.location.origin).pathname;
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const tokenInput = form.querySelector('input[name="_token"]');
  if (token && tokenInput) tokenInput.value = token;
  form.dataset.ajax = 'true';
  form.dataset.ajaxLabel = '\u062c\u0627\u0631\u064d \u0627\u0644\u062d\u0630\u0641';
  form.dataset.ajaxConfirm = '\u0647\u0644 \u062a\u0631\u064a\u062f \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628\u061f';
  form.removeAttribute('onsubmit');
});
</script>
@endpush

@push('scripts')
<script>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  form.querySelector('[name="details"]')?.setAttribute('required', 'required');
  const type = form.querySelector('[name="request_type_id"]');
  const modalFooter = form.querySelector('.modal-footer');
  const oldFields = form.querySelector('.row.g-2.mt-2');
  oldFields?.remove();
  const overtimeTypeIds = @json($requestTypes->filter(fn ($item) => $item->source_key === 'overtime')->pluck('id')->values());
  const fields = document.createElement('div');
  fields.className = 'row g-2 mt-2';
  fields.hidden = true;
  fields.innerHTML = '<div class="col-12"><label class="form-label">تاريخ الإضافي</label><input type="date" name="request_date" class="form-control"></div><div class="col-12"><label class="form-label">نوع ساعات الإضافي</label><select name="overtime_period" class="form-select"><option value="">اختر نوع الساعات</option><option value="day">ساعات عمل نهاري</option><option value="night">ساعات عمل ليلي</option></select></div><div class="col-12"><label class="form-label">عدد الساعات</label><input type="number" step="0.25" min="0.25" name="duration_hours" class="form-control"></div>';
  modalFooter?.before(fields);
  const date = fields.querySelector('input[type="date"]');
  const period = fields.querySelector('[name="overtime_period"]');
  const hours = fields.querySelector('input[type="number"]');
  period?.addEventListener('change', () => { hours.name = period.value === 'night' ? 'night_overtime_hours' : 'day_overtime_hours'; });
  const sync = () => {
    const isOvertime = overtimeTypeIds.map(String).includes(String(type?.value || ''));
    fields.hidden = !isOvertime;
    [date, period, hours].forEach((input) => { if (input) input.required = isOvertime; });
    if (isOvertime && !date.value) date.value = @json(today()->toDateString());
    if (!isOvertime) { date.value = ''; period.value = ''; hours.value = ''; }
  };
  type?.addEventListener('change', sync);
  sync();
})();
</script>
@endpush

@push('scripts')
<script>
document.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
  const form = methodInput.form;
  if (!form) return;
  const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = new URL('/requests/' + id, window.location.origin).pathname;
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const tokenInput = form.querySelector('input[name="_token"]');
  if (token && tokenInput) tokenInput.value = token;
  form.dataset.ajax = 'true';
  form.dataset.ajaxLabel = '\u062c\u0627\u0631\u064d \u0627\u0644\u062d\u0630\u0641';
  form.dataset.ajaxConfirm = '\u0647\u0644 \u062a\u0631\u064a\u062f \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628\u061f';
  form.removeAttribute('onsubmit');
});
</script>
@endpush

@push('scripts')
<script>
document.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
  const form = methodInput.form;
  if (!form) return;
  const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = new URL('/requests/' + id, window.location.origin).pathname;
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const tokenInput = form.querySelector('input[name="_token"]');
  if (token && tokenInput) tokenInput.value = token;
  form.dataset.ajax = 'true';
  form.dataset.ajaxLabel = '\u062c\u0627\u0631\u064d \u0627\u0644\u062d\u0630\u0641';
  form.dataset.ajaxConfirm = '\u0647\u0644 \u062a\u0631\u064a\u062f \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628\u061f';
  form.removeAttribute('onsubmit');
});
</script>
@endpush

@push('scripts')
<script>
window.addEventListener('load', () => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  const overtime = form.querySelector('.overtime-request-fields');
  const advance = form.querySelector('.advance-request-fields');
  const other = [...form.querySelectorAll('.request-conditional-card')].find((card) => card !== overtime && card !== advance);
  const overtimeDate = overtime?.querySelector('input[type="date"]');
  const overtimePeriod = overtime?.querySelector('[name="overtime_period"]');
  const overtimeHours = overtime?.querySelector('input[type="number"]');
  if (overtimeDate) overtimeDate.name = 'overtime_request_date';
  if (overtimeHours) {
    const syncOvertimeHoursName = () => { overtimeHours.name = overtimePeriod?.value === 'night' ? 'night_overtime_hours' : 'day_overtime_hours'; };
    overtimePeriod?.addEventListener('change', syncOvertimeHoursName);
    syncOvertimeHoursName();
  }
  const advanceDate = advance?.querySelector('input[type="date"]');
  if (advanceDate) advanceDate.name = 'advance_request_date';
  const otherDate = other?.querySelector('input[type="date"]');
  if (otherDate) otherDate.name = 'other_request_date';
});
</script>
@endpush

@push('styles')
<style>
  #requestCreateModal .request-conditional-card{width:min(100%,520px);margin:14px auto 0;border:1px solid #dbe7f5;border-radius:16px;background:linear-gradient(135deg,#f8fbff,#fff);padding:16px;box-shadow:0 8px 22px rgba(31,73,125,.06)}
  #requestCreateModal .request-conditional-card .form-label{font-weight:700;color:#16325c;margin-bottom:7px}
  #requestCreateModal .request-conditional-card .form-control,#requestCreateModal .request-conditional-card .form-select{border-radius:11px;min-height:44px;border-color:#d8e4f2}
  #requestCreateModal .request-conditional-card[hidden]{display:none!important}
  #requestCreateModal .advance-field-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 190px;gap:12px;align-items:end}
  #requestCreateModal .advance-limit-box{display:flex;flex-direction:column;gap:3px;padding:10px 12px;border:1px solid #f1aeb5;border-radius:11px;background:#fff5f5;color:#842029;min-height:76px}
  #requestCreateModal .advance-limit-box strong{font-size:16px}.advance-limit-box small{font-size:11px}.advance-warning{margin-top:12px;padding:10px 12px;border-radius:10px;background:#fff3cd;color:#856404;font-size:13px}.advance-confirm{margin-top:10px;color:#7a1f1f;font-size:13px}
  #requestCreateModal .advance-existing-notice{margin-top:12px;padding:10px 12px;border:1px solid #b6d4fe;border-radius:10px;background:#eef6ff;color:#084298;font-size:13px;line-height:1.7}
  #requestCreateModal .advance-existing-confirm{margin-top:10px;color:#7a1f1f;font-size:13px}
  @media(max-width:576px){#requestCreateModal .advance-field-grid{grid-template-columns:1fr}}
</style>
@endpush
@push('scripts')
<script>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  const isEditingRequest = form.dataset.editing === '1';
  const employees = @json($employees->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->full_name, 'code' => $employee->employee_code])->values());
  const employeeGrossSalaries = @json($employeeGrossSalaries);
  const employeeRequestMonths = @json($employeeRequestMonths);
  const employeeAdvanceWarnings = @json($employeeAdvanceWarnings);
  const employeeSelect = form.querySelector('select[name="employee_id"]');
  if (employeeSelect) {
    const wrapper = employeeSelect.parentElement;
    const lookup = document.createElement('input'); lookup.className = 'form-control'; lookup.name = 'employee_lookup'; lookup.setAttribute('list','requestEmployeesList'); lookup.placeholder = 'ابحث باسم الموظف أو كوده'; lookup.required = true;
    const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'employee_id'; hidden.required = true;
    const list = document.createElement('datalist'); list.id = 'requestEmployeesList';
    list.innerHTML = employees.map((employee) => `<option value="${employee.name} — #${employee.code}"></option>`).join('');
    employeeSelect.removeAttribute('name'); employeeSelect.removeAttribute('required'); employeeSelect.hidden = true;
    wrapper.append(lookup, hidden, list);
    const syncEmployee = () => {
      const query = lookup.value.trim().toLocaleLowerCase();
      const found = employees.find((employee) => `${employee.name} — #${employee.code}`.toLocaleLowerCase() === query || String(employee.code || '').toLocaleLowerCase() === query || String(employee.name || '').toLocaleLowerCase() === query);
      hidden.value = found?.id || '';
      hidden.dispatchEvent(new Event('change'));
    };
    lookup.addEventListener('input', syncEmployee); lookup.addEventListener('change', syncEmployee);
  }
  const type = form.querySelector('[name="request_type_id"]');
  const title = form.querySelector('[name="title"]');
  const typeMeta = @json($requestTypes->mapWithKeys(fn ($item) => [$item->id => ['label' => $item->display_name, 'source' => $item->source_key]]));
  let generatedTitle = '';
  const syncTitle = () => { const meta = typeMeta[type?.value]; if (!meta) return; if (!title.value.trim() || title.value === generatedTitle) title.value = meta.label; generatedTitle = meta.label; };
  type?.addEventListener('change', syncTitle); syncTitle();
  const overtimeCard = form.querySelector('.row.g-2.mt-2');
  const otherCard = document.createElement('div'); otherCard.className = 'request-conditional-card'; otherCard.hidden = true;
  otherCard.innerHTML = '<div class="mb-3"><label class="form-label">تاريخ الطلب المؤثر</label><input type="date" name="request_date" class="form-control"></div><div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="affects_payroll" value="1" id="requestAffectsPayroll"><label class="form-check-label" for="requestAffectsPayroll">يؤثر على دورة الرواتب</label></div><div class="row g-2" data-payroll-impact-fields hidden><div class="col-md-6"><label class="form-label">المبلغ</label><input type="number" name="impact_amount" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label class="form-label">نوع التأثير</label><select name="impact_type" class="form-select"><option value="">اختر نوع التأثير</option><option value="earning">مستحق</option><option value="deduction">مستقطع</option></select></div></div>';
  form.querySelector('.modal-footer')?.before(otherCard);
  const otherDate = otherCard.querySelector('[name="request_date"]'); const affects = otherCard.querySelector('[name="affects_payroll"]'); const impactFields = otherCard.querySelector('[data-payroll-impact-fields]'); const impactAmount = otherCard.querySelector('[name="impact_amount"]'); const impactType = otherCard.querySelector('[name="impact_type"]');
  const syncImpact = () => { const active = affects.checked; impactFields.hidden = !active; impactAmount.required = active; impactType.required = active; if (!active) { impactAmount.value = ''; impactType.value = ''; } };
  affects.addEventListener('change', syncImpact);
  const syncOther = () => { const isOther = typeMeta[type?.value]?.source === 'other'; otherCard.hidden = !isOther; otherDate.required = isOther; if (isOther && !otherDate.value) otherDate.value = @json(today()->toDateString()); if (!isOther) { otherDate.value = ''; affects.checked = false; syncImpact(); } };
  type?.addEventListener('change', syncOther); syncOther(); syncImpact();

  const advanceTypeIds = Object.entries(typeMeta).filter(([, meta]) => meta.source === 'advance').map(([id]) => String(id));
  const advanceCard = document.createElement('div');
  advanceCard.className = 'request-conditional-card advance-request-fields';
  advanceCard.hidden = true;
  advanceCard.innerHTML = '<div class="advance-field-grid"><div><label class="form-label">تاريخ طلب السلفة</label><input type="date" name="request_date" class="form-control" required></div><div><label class="form-label">مبلغ السلفة (جنيه)</label><input type="number" name="advance_amount" class="form-control" min="0.01" step="0.01"></div><div class="advance-limit-box"><span>الحد الافتراضي للسلفة</span><strong data-advance-limit>—</strong><small>10% من الراتب الإجمالي</small></div></div><div class="advance-warning" data-advance-warning hidden><i class="bi bi-exclamation-triangle"></i><span>المبلغ يتجاوز 10% من الراتب الإجمالي. يلزم تأكيد المتابعة.</span></div><div class="form-check advance-confirm" data-advance-confirm hidden><input class="form-check-input" type="checkbox" name="advance_over_limit_confirm" value="1" id="advanceOverLimitConfirm"><label class="form-check-label" for="advanceOverLimitConfirm">أؤكد أن مبلغ السلفة يتجاوز الحد الافتراضي وأرغب في المتابعة.</label></div>';
  form.querySelector('.modal-footer')?.before(advanceCard);
  const advanceAmount = advanceCard.querySelector('[name="advance_amount"]');
  const advanceDate = advanceCard.querySelector('[name="request_date"]');
  const advanceLimit = advanceCard.querySelector('[data-advance-limit]');
  const advanceWarning = advanceCard.querySelector('[data-advance-warning]');
  const advanceConfirm = advanceCard.querySelector('[name="advance_over_limit_confirm"]');
  const employeeIdInput = form.querySelector('input[name="employee_id"]');
  const existingAdvanceNotice = document.createElement('div');
  existingAdvanceNotice.className = 'advance-existing-notice';
  existingAdvanceNotice.hidden = true;
  advanceCard.append(existingAdvanceNotice);
  const existingAdvanceConfirm = document.createElement('input');
  existingAdvanceConfirm.type = 'checkbox';
  existingAdvanceConfirm.name = 'advance_existing_confirm';
  existingAdvanceConfirm.value = '1';
  existingAdvanceConfirm.required = false;
  const existingAdvanceConfirmLabel = document.createElement('label');
  existingAdvanceConfirmLabel.className = 'form-check-label';
  existingAdvanceConfirmLabel.textContent = 'أؤكد أنني أريد إنشاء سلفة جديدة رغم وجود رصيد سابق غير مسدد.';
  const existingAdvanceConfirmWrap = document.createElement('div');
  existingAdvanceConfirmWrap.className = 'form-check advance-existing-confirm';
  existingAdvanceConfirmWrap.hidden = true;
  existingAdvanceConfirmWrap.append(existingAdvanceConfirm, existingAdvanceConfirmLabel);
  advanceCard.append(existingAdvanceConfirmWrap);
  const formatMoney = (value) => `${Number(value || 0).toLocaleString('ar-EG', {maximumFractionDigits: 2})} ج.م`;
  const syncAdvance = () => {
    const active = advanceTypeIds.includes(String(type?.value || ''));
    advanceCard.hidden = !active;
    if (!active) { advanceDate.value = ''; advanceDate.required = false; advanceAmount.value = ''; advanceAmount.required = false; advanceWarning.hidden = true; advanceConfirm.checked = false; advanceConfirm.required = false; return; }
    advanceDate.required = true;
    if (!advanceDate.value) advanceDate.value = @json(today()->toDateString());
    advanceAmount.required = true;
    const gross = Number(employeeGrossSalaries[employeeIdInput?.value] || 0);
    const limit = gross * 0.10;
    advanceLimit.textContent = gross > 0 ? formatMoney(limit) : 'اختر الموظف أولًا';
    const overLimit = Number(advanceAmount.value || 0) > limit;
    advanceWarning.hidden = !overLimit;
    advanceConfirm.closest('.advance-confirm').hidden = !overLimit;
    advanceConfirm.required = overLimit;
    if (!overLimit) advanceConfirm.checked = false;
    const monthKey = advanceDate.value ? advanceDate.value.slice(0, 7) : '';
    const duplicate = (employeeRequestMonths[employeeIdInput?.value] || []).includes(monthKey);
    const previous = employeeAdvanceWarnings[employeeIdInput?.value];
    if (isEditingRequest) {
      existingAdvanceNotice.hidden = true;
      existingAdvanceConfirmWrap.hidden = true;
      existingAdvanceConfirm.required = false;
      existingAdvanceConfirm.checked = false;
      return;
    }
    existingAdvanceNotice.hidden = !previous && !duplicate;
    existingAdvanceNotice.innerHTML = duplicate
      ? '<i class="bi bi-exclamation-triangle"></i> يوجد طلب قائم لهذا الموظف داخل نفس الشهر، وسيتم رفض الطلب المكرر.'
      : previous
        ? `<i class="bi bi-info-circle"></i> سبق للموظف الحصول على سلفة بقيمة ${formatMoney(previous.original_amount)} بتاريخ ${previous.date || '—'}، والمتبقي منها ${formatMoney(previous.amount)}.`
        : '';
    existingAdvanceConfirmWrap.hidden = !previous;
    existingAdvanceConfirm.required = !!previous;
    if (!previous) existingAdvanceConfirm.checked = false;
  };
  advanceAmount.addEventListener('input', syncAdvance);
  employeeIdInput?.addEventListener('change', syncAdvance);
  type?.addEventListener('change', syncAdvance);
  syncAdvance();

  // Multiple conditional sections use request_date. Submit only the date
  // belonging to the selected request type so hidden sections cannot
  // overwrite the active value with an empty one.
  form.addEventListener('submit', () => {
    const source = typeMeta[type?.value]?.source;
    const activeDate = source === 'advance'
      ? advanceDate
      : source === 'overtime'
        ? overtimeCard?.querySelector('[name="request_date"]')
        : source === 'other'
          ? otherDate
          : null;
    form.querySelectorAll('[name="request_date"]').forEach((input) => {
      input.disabled = input !== activeDate;
    });
    if (activeDate) activeDate.disabled = false;
  });
})();
</script>
@endpush
@push('scripts')
<script>
const requestCardData=@json($requestCardDetails);const requestKeys=['all','pending','approved','rejected'];const requestTitles=['إجمالي الطلبات','الطلبات المعلقة','الطلبات المعتمدة','الطلبات المرفوضة'];document.querySelectorAll('[data-request-kpi]').forEach(card=>card.addEventListener('click',()=>{const i=Number(card.dataset.requestKpi);const list=requestCardData[requestKeys[i]]||[];document.getElementById('requestCardTitle').textContent=requestTitles[i];document.getElementById('requestCardBody').innerHTML=list.length?list.map(item=>'<div class="employee-kpi-detail-row"><span class="employee-kpi-detail-avatar">'+(item.employee?.initials||item.employee?.full_name?.slice(0,1)||'—')+'</span><div><a href="{{ url('/employees') }}/'+(item.employee_id||'')+'">'+(item.employee?.full_name||'—')+'</a><small>'+item.title+' · '+(item.status||'')+'</small></div></div>').join(''):'<div class="text-muted text-center p-4">لا توجد طلبات.</div>';bootstrap.Modal.getOrCreateInstance(document.getElementById('requestCardDetails')).show();}));
</script>
@endpush

@push('scripts')
<script>
(() => {
  const requestValueData = @json($requestValueData);
  const editingRequest = @json($editingRequestData);
  if (editingRequest) {
    const editForm = document.querySelector('#requestCreateModal form');
    if (editForm) {
      editForm.dataset.editingId = String(editingRequest.id || '');
      const title = editForm.querySelector('[name="title"]');
      const details = editForm.querySelector('[name="details"]');
      const employee = editForm.querySelector('[name="employee_id"]');
      const type = editForm.querySelector('[name="request_type_id"]');
      if (title) title.value = editingRequest.title || '';
      if (details) { details.value = editingRequest.details || ''; details.required = false; }
      if (employee) { employee.value = editingRequest.employee_id || ''; employee.disabled = true; employee.required = false; }
      if (type) { type.value = editingRequest.request_type_id || ''; type.disabled = true; type.required = false; }
      const heading = document.getElementById('requestCreateTitle');
      if (heading) heading.textContent = '\u062a\u0639\u062f\u064a\u0644 \u0627\u0644\u0637\u0644\u0628';
      const submit = editForm.querySelector('button[type="submit"]');
      if (submit) submit.textContent = '\u062d\u0641\u0638 \u0627\u0644\u062a\u0639\u062f\u064a\u0644';
      const applyEditingFields = () => {
        const payload = editingRequest.payload || {};
        [employee, type].forEach((field) => field?.closest('.mb-3') && (field.closest('.mb-3').hidden = true));
        const note = document.createElement('div');
        note.className = 'alert alert-info py-2 mb-3';
        note.textContent = '\u0627\u0644\u0645\u0648\u0638\u0641 \u0648\u0646\u0648\u0639 \u0627\u0644\u0637\u0644\u0628 \u062b\u0627\u0628\u062a\u0627\u0646 \u0623\u062b\u0646\u0627\u0621 \u0627\u0644\u062a\u0639\u062f\u064a\u0644.';
        editForm.querySelector('.modal-body')?.prepend(note);
        const lookup = editForm.querySelector('[name="employee_lookup"]');
        const hiddenEmployee = editForm.querySelector('input[name="employee_id"]');
        if (lookup) lookup.value = editingRequest.employee_name || '';
        if (hiddenEmployee) hiddenEmployee.value = editingRequest.employee_id || '';
        const set = (name, value) => { const field = editForm.querySelector(`[name="${name}"]`); if (field && value !== undefined && value !== null) field.value = value; };
        set('advance_request_date', payload.request_date); set('advance_amount', payload.amount);
        set('overtime_request_date', payload.request_date); set('overtime_period', payload.overtime_period);
        set('day_overtime_hours', payload.overtime_period === 'day' ? payload.hours : ''); set('night_overtime_hours', payload.overtime_period === 'night' ? payload.hours : '');
        set('other_request_date', payload.request_date); set('impact_amount', payload.amount); set('impact_type', payload.impact_type);
        const affects = editForm.querySelector('[name="affects_payroll"]'); if (affects) affects.checked = !!payload.affects_payroll;
        set('request_date', payload.request_date); set('start_date', payload.start_date); set('end_date', payload.end_date); set('duration_days', payload.days); set('duration_hours', payload.hours); set('addition_amount', payload.addition_amount); set('deduction_amount', payload.deduction_amount);
        editForm.dispatchEvent(new Event('change', { bubbles: true }));
      };
      applyEditingFields();
    }
  }
  const table = document.querySelector('.panel.table-panel table');
  const header = table?.tHead?.rows[0];
  if (!table || !header) return;
  const valueHeader = document.createElement('th');
  valueHeader.dataset.requestValue = 'true';
  valueHeader.textContent = '\u0627\u0644\u0642\u064a\u0645\u0629';
  header.cells[3]?.before(valueHeader);
  [...table.tBodies].flatMap((body) => [...body.rows]).forEach((row) => {
    row.dataset.requestId ||= row.querySelector('a[href*="request="]')?.href.match(/request=(\d+)/)?.[1] || '';
    const valueCell = document.createElement('td');
    valueCell.textContent = requestValueData[row.dataset.requestId] || '—';
    row.cells[3]?.before(valueCell);
  });
  const canEdit = @json(auth()->user()?->hasPermission('requests.create'));
  const canDelete = @json(auth()->user()?->hasPermission('requests.delete'));
  [...table.tBodies].flatMap((body) => [...body.rows]).forEach((row) => {
    const id = row.dataset.requestId || row.querySelector('a[href*="request="]')?.href.match(/request=(\d+)/)?.[1];
    const actions = row.lastElementChild;
    if (!id || !actions) return;
    const buttons = [];
    if (canEdit) buttons.push(`<a class="btn btn-sm btn-outline-primary" href="?edit=${id}" title="تعديل"><i class="bi bi-pencil"></i></a>`);
    if (canDelete) buttons.push(`<form method="POST" action="/requests/${id}"><input type="hidden" name="_token" value="${typeof csrfToken !== 'undefined' ? csrfToken : ''}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>`);
    if (buttons.length) actions.innerHTML = `<div class="d-flex gap-1 align-items-center">${buttons.join('')}</div>`;
  });
})();
</script>
@endpush

<div class="panel table-panel">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>الطلب</th><th>الموظف</th><th>النوع</th><th>التاريخ</th><th>الحالة</th><th>الإجراء</th></tr></thead>
            <tbody>
            @forelse($requests as $hrRequest)
                <tr>
                    <td>
                        <a href="{{ route('requests.index', array_merge(request()->except('page'), ['request' => $hrRequest->id])) }}" class="fw-bold">{{ $hrRequest->title }}</a>
                        @if($hrRequest->details)<div class="text-muted small mt-1">{{ mb_strimwidth($hrRequest->details, 0, 70, '…') }}</div>@endif
                    </td>
                    <td><a href="{{ $hrRequest->employee ? route('employees.show', $hrRequest->employee) : '#' }}">{{ $hrRequest->employee?->full_name ?? '—' }}</a></td>
                    <td><span class="badge-soft info">{{ $hrRequest->requestType?->display_name ?? $hrRequest->request_type }}</span></td>
                    <td>{{ $hrRequest->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td><span class="badge-soft {{ $hrRequest->status_badge_class }}">{{ $hrRequest->status_label }}</span></td>
                    <td>
                        <div class="d-flex gap-1 align-items-center">
                            <a class="btn btn-sm btn-light" href="{{ route('requests.index', array_merge(request()->except('page'), ['request' => $hrRequest->id])) }}" title="التفاصيل"><i class="bi bi-eye"></i></a>
                            @if(in_array($hrRequest->status, ['Pending', 'Manager Approved'], true) && auth()->user()?->hasPermission('requests.approve'))
                                <form method="POST" action="{{ route('requests.approve', $hrRequest) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-success" title="اعتماد"><i class="bi bi-check-lg"></i></button></form>
                                <form method="POST" action="{{ route('requests.reject', $hrRequest) }}">@csrf @method('PATCH')<input type="hidden" name="rejection_reason" value="تم الرفض من شاشة الطلبات"><button class="btn btn-sm btn-outline-danger" title="رفض"><i class="bi bi-x-lg"></i></button></form>
                            @endif
                            @if($hrRequest->status === 'Pending' && auth()->user()?->hasPermission('requests.delete'))
                                <form method="POST" action="{{ route('requests.destroy', $hrRequest) }}" onsubmit="return confirm('هل تريد حذف الطلب المعلق؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5"><div class="empty-icon"><i class="bi bi-inbox"></i></div><h2 class="h5 mt-2">لا توجد طلبات</h2><p class="text-muted mb-0">لا توجد طلبات مطابقة للفلاتر الحالية.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<x-pagination-controls :paginator="$requests" />

@if(auth()->user()?->hasPermission('requests.create'))
<div class="modal fade" id="requestCreateModal" tabindex="-1" aria-labelledby="requestCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" data-editing="{{ $editingRequest ? '1' : '0' }}" action="{{ $editingRequest ? route('requests.update', $editingRequest) : route('requests.store') }}">
                @csrf @if($editingRequest) @method('PUT') @endif
                <div class="modal-header"><h5 class="modal-title" id="requestCreateTitle">طلب HR جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">الموظف</label><select name="employee_id" class="form-select" required><option value="">اختر الموظف</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->full_name }} @if($employee->employee_code) · {{ $employee->employee_code }} @endif</option>@endforeach</select></div>
                    <div class="mb-3"><label class="form-label">نوع الطلب</label><select name="request_type_id" class="form-select" required><option value="">اختر النوع</option>@foreach($requestTypes as $type)<option value="{{ $type->id }}" @selected(old('request_type_id') == $type->id)>{{ $type->display_name }}</option>@endforeach</select></div>
                    <div class="mb-3"><label class="form-label">عنوان الطلب</label><input name="title" class="form-control" maxlength="200" value="{{ old('title') }}" placeholder="مثال: طلب سلفة شهر أكتوبر" required></div>
                    <div><label class="form-label">التفاصيل</label><textarea name="details" class="form-control" rows="4" maxlength="5000" placeholder="أضف التفاصيل أو المبررات اللازمة...">{{ old('details') }}</textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">إرسال الطلب</button></div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
  // Stage 4: approval/rejection of an HR request updates only its table row.
  document.querySelectorAll('table form[action*="/requests/"][action$="/approve"], table form[action*="/requests/"][action$="/reject"]').forEach((form) => {
    form.dataset.ajax = 'true';
    form.dataset.ajaxLabel = form.action.endsWith('/reject') ? 'جارٍ الرفض' : 'جارٍ الاعتماد';
  });

  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.closest('table') || !form.matches('form[action*="/requests/"][action$="/approve"], form[action*="/requests/"][action$="/reject"]')) return;
    const row = form.closest('tr');
    if (!row) return;
    const status = event.detail?.request?.status || (form.action.endsWith('/reject') ? 'Rejected' : 'Approved');
    const statusCell = row.children[4];
    statusCell.innerHTML = status === 'Rejected'
      ? '<span class="badge-soft danger">مرفوض</span>'
      : '<span class="badge-soft success">معتمد</span>';
    row.children[5].querySelectorAll('form[action$="/approve"], form[action$="/reject"]').forEach((actionForm) => actionForm.remove());
  });
</script>
@endpush

@push('scripts')
<script>
  // Stage 5: creation/deletion use the same guarded AJAX path as review actions.
  const requestCreateForm = document.querySelector('#requestCreateModal form');
  if (requestCreateForm && !requestCreateForm.querySelector('[name="duration_days"]')) {
    const fields = document.createElement('div');
    fields.className = 'row g-2 mt-2';
    fields.innerHTML = '<div class="col-md-4"><label class="form-label">تاريخ الطلب</label><input type="date" name="request_date" class="form-control"></div><div class="col-md-4"><label class="form-label">من</label><input type="date" name="start_date" class="form-control"></div><div class="col-md-4"><label class="form-label">إلى</label><input type="date" name="end_date" class="form-control"></div><div class="col-md-3"><label class="form-label">الأيام</label><input type="number" step="0.5" min="0" name="duration_days" class="form-control"></div><div class="col-md-3"><label class="form-label">الساعات</label><input type="number" step="0.5" min="0" name="duration_hours" class="form-control"></div><div class="col-md-3"><label class="form-label">إضافة مالية</label><input type="number" step="0.01" min="0" name="addition_amount" class="form-control"></div><div class="col-md-3"><label class="form-label">خصم مالي</label><input type="number" step="0.01" min="0" name="deduction_amount" class="form-control"></div>';
    requestCreateForm.querySelector('.modal-footer')?.before(fields);
  }
  const requestBaseUrl = @json(url('/requests'));
  const requestTableBody = document.querySelector('.table-panel table tbody');
  const canDeleteHrRequest = @json(auth()->user()?->hasPermission('requests.delete'));
  const escapeRequestHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const requestDate = (value) => { const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ar-EG', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const requestRow = (item) => {
    const employee = item.employee || {};
    const id = Number(item.id);
    const detailUrl = `${requestBaseUrl}?request=${id}`;
    const remove = item.status === 'Pending' && canDeleteHrRequest ? `<form method="POST" action="${requestBaseUrl}/${id}" onsubmit="return confirm('هل تريد حذف الطلب المعلق؟')"><input type="hidden" name="_token" value="${escapeRequestHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>` : '';
    return `<tr data-request-id="${id}"><td><a href="${detailUrl}" class="fw-bold">${escapeRequestHtml(item.title)}</a>${item.details ? `<div class="text-muted small mt-1">${escapeRequestHtml(item.details).slice(0, 70)}${String(item.details).length > 70 ? '…' : ''}</div>` : ''}</td><td><a href="${escapeRequestHtml(employee.id ? `{{ url('/employees') }}/${employee.id}` : '#')}">${escapeRequestHtml(employee.full_name)}</a></td><td><span class="badge-soft info">${escapeRequestHtml(item.request_type_label)}</span></td><td>${requestDate(item.created_at)}</td><td><span class="badge-soft warning">معلق</span></td><td><div class="d-flex gap-1 align-items-center"><a class="btn btn-sm btn-light" href="${detailUrl}" title="التفاصيل"><i class="bi bi-eye"></i></a>${remove}</div></td></tr>`;
  };
  const wireHrRequestAjaxForms = (root = document) => {
    root.querySelectorAll('#requestCreateModal form').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الإرسال'; });
    root.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => { const form = methodInput.form; if (!form) return; form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحذف'; form.dataset.ajaxConfirm = 'هل تريد حذف الطلب المعلق؟'; form.removeAttribute('onsubmit'); });
  };
  wireHrRequestAjaxForms();
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.closest('#requestCreateModal')) {
      const item = event.detail?.request;
      if (item && requestTableBody && form.dataset.editingId) {
        const row = requestTableBody.querySelector(`tr[data-request-id="${form.dataset.editingId}"]`);
        if (row) {
          const titleCell = row.children[0];
          const titleLink = titleCell?.querySelector('a');
          if (titleLink) titleLink.textContent = item.title || '';
          const details = titleCell?.querySelector('.text-muted');
          if (item.details && details) details.textContent = item.details;
          if (row.children[3] && item.value !== undefined) row.children[3].textContent = item.value || '—';
          if (row.children[5]) row.children[5].innerHTML = '<span class="badge-soft success">معتمد</span>';
        }
      } else if (item && requestTableBody) {
        requestTableBody.querySelector('td[colspan]')?.closest('tr')?.remove();
        requestTableBody.insertAdjacentHTML('afterbegin', requestRow(item));
        const addedRow = requestTableBody.querySelector(`tr[data-request-id="${Number(item.id)}"]`);
        if (addedRow) {
          const actions = addedRow.lastElementChild;
          if (actions) {
            const edit = @json(auth()->user()?->hasPermission('requests.create')) ? `<a class="btn btn-sm btn-outline-primary" href="?edit=${Number(item.id)}" title="تعديل"><i class="bi bi-pencil"></i></a>` : '';
            const remove = @json(auth()->user()?->hasPermission('requests.delete')) ? `<form method="POST" action="${requestBaseUrl}/${Number(item.id)}"><input type="hidden" name="_token" value="${escapeRequestHtml(document.querySelector('meta[name=\"csrf-token\"]')?.content || '')}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>` : '';
            actions.innerHTML = `<div class="d-flex gap-1 align-items-center">${edit}${remove}</div>`;
          }
          if (addedRow.children[4]) addedRow.children[4].innerHTML = '<span class="badge-soft success">معتمد</span>';
        }
        wireHrRequestAjaxForms(requestTableBody);
      }
      bootstrap.Modal.getInstance(document.getElementById('requestCreateModal'))?.hide(); form.reset(); delete form.dataset.editingId;
    }
    if (form.querySelector('input[name="_method"][value="DELETE"]') && form.action.includes('/requests/')) form.closest('tr')?.remove();
  });
</script>
@endpush

@if(request()->boolean('create') || request()->filled('edit') || $errors->hasAny(['employee_id', 'request_type_id', 'title', 'details']))
@push('scripts')
<script>document.addEventListener('DOMContentLoaded',function(){const m=document.getElementById('requestCreateModal');if(m){bootstrap.Modal.getOrCreateInstance(m).show();}});</script>
@endpush
@endif

@push('scripts')
<script>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  const type = form.querySelector('[name="request_type_id"]');
  const modalFooter = form.querySelector('.modal-footer');
  form.querySelectorAll('.row.g-2.mt-2').forEach((fields) => fields.remove());
  const overtimeTypeIds = @json($requestTypes->filter(fn ($item) => $item->source_key === 'overtime')->pluck('id')->values());
  const fields = document.createElement('div');
  fields.className = 'request-conditional-card overtime-request-fields';
  fields.hidden = true;
  fields.innerHTML = '<div class="col-12"><label class="form-label">تاريخ الإضافي</label><input type="date" name="request_date" class="form-control" required></div><div class="col-12"><label class="form-label">نوع ساعات الإضافي</label><select name="overtime_period" class="form-select" required><option value="">اختر نوع الساعات</option><option value="day">ساعات عمل نهاري</option><option value="night">ساعات عمل ليلي</option></select></div><div class="col-12"><label class="form-label">عدد الساعات</label><input type="number" step="0.25" min="0.25" name="duration_hours" class="form-control" required></div>';
  modalFooter?.before(fields);
  const date = fields.querySelector('[name="request_date"]');
  const period = fields.querySelector('[name="overtime_period"]');
  const hours = fields.querySelector('[name="duration_hours"]');
  const sync = () => {
    const isOvertime = overtimeTypeIds.map(String).includes(String(type?.value || ''));
    fields.hidden = !isOvertime;
    [date, period, hours].forEach((input) => { if (input) input.required = isOvertime; });
    if (isOvertime && !date.value) date.value = @json(today()->toDateString());
    if (!isOvertime) { date.value = ''; period.value = ''; hours.value = ''; }
  };
  type?.addEventListener('change', sync);
  sync();
})();
</script>
@endpush

@push('scripts')
<script>
document.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
  const form = methodInput.form;
  if (!form) return;
  const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = new URL('/requests/' + id, window.location.origin).pathname;
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const tokenInput = form.querySelector('input[name="_token"]');
  if (token && tokenInput) tokenInput.value = token;
  form.dataset.ajax = 'true';
  form.dataset.ajaxLabel = '\u062c\u0627\u0631\u064d \u0627\u0644\u062d\u0630\u0641';
  form.dataset.ajaxConfirm = '\u0647\u0644 \u062a\u0631\u064a\u062f \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628\u061f';
  form.removeAttribute('onsubmit');
});
</script>
@endpush
