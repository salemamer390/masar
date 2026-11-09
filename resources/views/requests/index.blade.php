@extends('layouts.app')
@section('title', 'الطلبات')

@section('content')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('a[href*="create=1"]');
  if (!button) return;
  const clearRequestToast = () => {
    const toast = document.getElementById('liveToast');
    bootstrap.Toast.getInstance(toast)?.hide();
    const text = document.getElementById('toastText');
    if (text) text.textContent = '';
  };
  document.getElementById('requestCreateModal')?.addEventListener('show.bs.modal', clearRequestToast);
  document.getElementById('requestCreateModal')?.addEventListener('input', clearRequestToast);
  document.getElementById('requestCreateModal')?.addEventListener('change', clearRequestToast);
  button.id = 'newRequestButton';
  button.href = '#requestCreateModal';
  button.dataset.storeUrl = @json(route('requests.store'));
  button.dataset.bsToggle = 'modal';
  button.addEventListener('click', () => {
    const form = document.querySelector('#requestCreateModal form');
    if (!form) return;
    // Do not carry a validation toast from a previous request into a new one.
    clearRequestToast();
    form.action = button.dataset.storeUrl;
    form.dataset.editing = '0';
    delete form.dataset.editingId;
    form.querySelector('input[name="_method"]')?.remove();
    form.reset();
    form.querySelectorAll('[name="employee_id"], [name="request_type_id"]').forEach((field) => {
      field.disabled = false; field.required = true; field.closest('.mb-3')?.removeAttribute('hidden');
    });
    const lookup = form.querySelector('[name="employee_lookup"]');
    if (lookup) { lookup.value = ''; lookup.required = true; }
    form.querySelector('input[name="employee_id"]')?.removeAttribute('disabled');
    form.querySelectorAll('.modal-body .alert-info').forEach((note) => note.remove());
    form.querySelector('[name="request_type_id"]')?.dispatchEvent(new Event('change', { bubbles: true }));
  });
});
</script>
<link rel="stylesheet" href="{{ asset('css/pages/requests-index-01.css') }}?v={{ filemtime(public_path('css/pages/requests-index-01.css')) }}">
@push('scripts')
<script>
(() => {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const requestRoot = @json(url('/requests'));
  const prepareDeleteForms = (root = document) => root.querySelectorAll('form[action*="/requests/"] input[name="_method"][value="DELETE"]').forEach((input) => {
    const form = input.form;
    if (!form) return;
    form.classList.add('request-delete-form');
    // The global AJAX handler must not claim this form; the request-specific
    // handler below removes the row only after the server confirms deletion.
    form.dataset.ajax = 'native';
    const id = form.action.match(/\/requests\/(\d+)/)?.[1];
  if (id) form.action = @json(url('/requests')) + '/' + id;
    const token = form.querySelector('input[name="_token"]');
    if (token) token.value = csrf();
  });
  prepareDeleteForms();
  const table = document.querySelector('.table-panel');
  if (table) new MutationObserver(() => prepareDeleteForms(table)).observe(table, { childList: true, subtree: true });
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('request-delete-form')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.busy === '1') return;
    if (!window.confirm('\u0647\u0644 \u062a\u0631\u064a\u062f \u062d\u0630\u0641 \u0647\u0630\u0627 \u0627\u0644\u0637\u0644\u0628\u061f')) return;
    form.dataset.busy = '1';
    const row = form.closest('tr');
    const employeeId = row?.dataset.employeeId || row?.querySelector('td:nth-child(2) a[href*="/employees/"]')?.href.match(/\/employees\/(\d+)/)?.[1] || '';
    row?.classList.add('request-row-removing');
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || '\u062a\u0639\u0630\u0631 \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628.');
      document.dispatchEvent(new CustomEvent('masar:request-deleted', { detail: { ...payload, employee_id: payload.employee_id || employeeId } }));
      setTimeout(() => row?.remove(), 260);
      window.showToast?.(payload.message || '\u062a\u0645 \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628 \u0628\u0646\u062c\u0627\u062d.');
    } catch (error) {
      form.dataset.busy = '0';
      window.showToast?.(error.message || '\u062a\u0639\u0630\u0631 \u062d\u0630\u0641 \u0627\u0644\u0637\u0644\u0628.');
    }
  }, true);
})();
</script>
@endpush
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

        ['طلبات مؤكدة', $stats['approved'], 'green', 'check-circle'],
        ['طلبات ملغاة', $stats['rejected'], 'red', 'x-circle'],
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
        @foreach(['Approved'=>'مؤكد','Rejected'=>'ملغى'] as $value => $label)
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
    @if(false)
        @if(auth()->user()?->hasPermission('requests.approve'))
            <div class="d-flex gap-2 px-3 pb-3">
                <form method="POST" action="{{ route('requests.approve', $selectedRequest) }}">@csrf @method('PATCH')<button class="btn btn-success"><i class="bi bi-check-lg"></i> اعتماد</button></form>
                <form method="POST" action="{{ route('requests.reject', $selectedRequest) }}" class="d-flex gap-2 flex-grow-1"><input type="text" name="rejection_reason" class="form-control" placeholder="سبب الرفض (اختياري)">@csrf @method('PATCH')<button class="btn btn-outline-danger">رفض</button></form>
            </div>
        @endif
    @endif
</div>
@endif

@include('requests.partials.create-behavior')
@push('scripts')
<script>
const requestCardData=@json($requestCardDetails);const requestKeys=['all','approved','rejected'];const requestTitles=['إجمالي الطلبات','الطلبات المؤكدة','الطلبات الملغاة'];document.querySelectorAll('[data-request-kpi]').forEach(card=>card.addEventListener('click',()=>{const i=Number(card.dataset.requestKpi);const list=requestCardData[requestKeys[i]]||[];document.getElementById('requestCardTitle').textContent=requestTitles[i];document.getElementById('requestCardBody').innerHTML=list.length?list.map(item=>'<div class="employee-kpi-detail-row"><span class="employee-kpi-detail-avatar">'+(item.employee?.initials||item.employee?.full_name?.slice(0,1)||'—')+'</span><div><a href="{{ url('/employees') }}/'+(item.employee_id||'')+'">'+(item.employee?.full_name||'—')+'</a><small>'+item.title+' · '+(item.status||'')+'</small></div></div>').join(''):'<div class="text-muted text-center p-4">لا توجد طلبات.</div>';bootstrap.Modal.getOrCreateInstance(document.getElementById('requestCardDetails')).show();}));
</script>
@endpush

@push('scripts')
<script>
(() => {
  const requestValueData = @json($requestValueData);
  const editingRequest = @json($editingRequestData);
  if (editingRequest) {
    const cleanEditUrl = new URL(window.location.href);
    cleanEditUrl.searchParams.delete('edit');
    window.history.replaceState({}, document.title, cleanEditUrl.pathname + (cleanEditUrl.search ? `?${cleanEditUrl.searchParams.toString()}` : '') + cleanEditUrl.hash);
    const editForm = document.querySelector('#requestCreateModal form');
    if (editForm) {
      editForm.dataset.editingId = String(editingRequest.id || '');
      const title = editForm.querySelector('[name="title"]');
      const details = editForm.querySelector('[name="details"]');
      const employee = editForm.querySelector('[name="employee_id"]');
      const type = editForm.querySelector('[name="request_type_id"]');
      editForm.querySelectorAll('[name="status"], [name="request_status"], [data-request-status-field]').forEach((field) => {
        const group = field.closest('.mb-3, .form-group, .row, .request-status-field');
        if (group && group !== editForm) group.remove(); else field.remove();
      });
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
        const advanceCard = editForm.querySelector('.advance-request-fields');
        if (advanceCard && editingRequest.request_type_source === 'advance') {
          advanceCard.hidden = false;
          advanceCard.querySelector('[name="advance_amount"]')?.setAttribute('required', 'required');
          advanceCard.querySelector('[name="advance_request_date"]')?.setAttribute('required', 'required');
        }
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
        if (advanceCard) {
          const advanceDateField = advanceCard.querySelector('input[type="date"]');
          const advanceAmountField = advanceCard.querySelector('[name="advance_amount"]');
          if (advanceDateField) advanceDateField.value = payload.request_date || '';
          if (advanceAmountField) advanceAmountField.value = payload.amount ?? '';
          advanceAmountField?.dispatchEvent(new Event('input', { bubbles: true }));
        }
        set('overtime_request_date', payload.request_date); set('overtime_period', payload.overtime_period);
        set('day_overtime_hours', payload.overtime_period === 'day' ? payload.hours : ''); set('night_overtime_hours', payload.overtime_period === 'night' ? payload.hours : '');
        set('permission_from_time', payload.from_time); set('permission_to_time', payload.to_time); set('from_time', payload.from_time); set('to_time', payload.to_time);
        const overtimeEditCard = editForm.querySelector('.overtime-request-fields');
        if (overtimeEditCard && editingRequest.request_type_source === 'overtime') {
          overtimeEditCard.hidden = false;
          const overtimeEditDate = overtimeEditCard.querySelector('[name="overtime_request_date"]');
          const overtimeEditPeriod = overtimeEditCard.querySelector('[name="overtime_period"]');
          const overtimeEditHours = overtimeEditCard.querySelector('input[type="number"]');
          if (overtimeEditDate) overtimeEditDate.value = payload.request_date || '';
          if (overtimeEditPeriod) {
            overtimeEditPeriod.value = payload.overtime_period || '';
            overtimeEditPeriod.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (overtimeEditHours) overtimeEditHours.value = payload.hours ?? '';
        }
        const financialCard = editForm.querySelector('.financial-request-fields');
        if (financialCard && ['bonus', 'incentive', 'commission', 'profit_share'].includes(editingRequest.request_type_source)) {
          financialCard.hidden = false;
          const financialDate = financialCard.querySelector('[name="financial_request_date"]');
          const financialAmount = financialCard.querySelector('[name="financial_amount"]');
          if (financialDate) { financialDate.value = payload.request_date || ''; financialDate.required = true; }
          if (financialAmount) { financialAmount.value = payload.amount ?? ''; financialAmount.required = true; }
        }
        set('other_request_date', payload.request_date); set('impact_amount', payload.amount); set('impact_type', payload.impact_type);
        const affects = editForm.querySelector('[name="affects_payroll"]'); if (affects) affects.checked = !!payload.affects_payroll;
        set('permission_from_time', payload.from_time); set('permission_to_time', payload.to_time); set('from_time', payload.from_time); set('to_time', payload.to_time); set('request_date', payload.request_date); set('start_date', payload.start_date); set('end_date', payload.end_date); set('duration_days', payload.days); set('duration_hours', payload.hours); set('addition_amount', payload.addition_amount); set('deduction_amount', payload.deduction_amount);
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
    valueCell.dataset.requestValueCell = 'true';
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
            <thead><tr><th>الطلب</th><th>الموظف</th><th>النوع</th><th>التاريخ</th><th>الإجراء</th></tr></thead>
            <tbody>
            @forelse($requests as $hrRequest)
                <tr data-request-id="{{ $hrRequest->id }}" data-employee-id="{{ $hrRequest->employee_id }}" data-request-date="{{ data_get($hrRequest->payload, 'request_date') ?: $hrRequest->created_at?->toIso8601String() }}">
                    <td>
                        <a href="{{ route('requests.index', array_merge(request()->except('page'), ['request' => $hrRequest->id])) }}" class="fw-bold">{{ $hrRequest->title }}</a>
                        @if($hrRequest->details)<div class="text-muted small mt-1">{{ mb_strimwidth($hrRequest->details, 0, 70, '…') }}</div>@endif
                    </td>
                    <td><a href="{{ $hrRequest->employee ? route('employees.show', $hrRequest->employee) : '#' }}">{{ $hrRequest->employee?->full_name ?? '—' }}</a></td>
                    <td><span class="badge-soft info">{{ $hrRequest->requestType?->display_name ?? $hrRequest->request_type }}</span></td>
                    <td>{{ $hrRequest->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>
                        <div class="d-flex gap-1 align-items-center">
                            <a class="btn btn-sm btn-light" href="{{ route('requests.index', array_merge(request()->except('page'), ['request' => $hrRequest->id])) }}" title="التفاصيل"><i class="bi bi-eye"></i></a>
                            @if(false)
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
@include('requests.partials.create-modal')
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
    const statusCell = row.querySelector('[data-request-status-cell]');
    if (!statusCell) return;
    statusCell.innerHTML = status === 'Rejected'
      ? '<span class="badge-soft danger">مرفوض</span>'
      : '<span class="badge-soft success">معتمد</span>';
    row.lastElementChild?.querySelectorAll('form[action$="/approve"], form[action$="/reject"]').forEach((actionForm) => actionForm.remove());
  });
</script>
@endpush

@push('scripts')
<script>
  // Keep the visible date in sync after editing, before the main success
  // handler clears the editing marker.
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.closest('#requestCreateModal') || !form.dataset.editingId) return;
    const dateValue = event.detail?.request?.request_date || event.detail?.request?.created_at;
    const row = requestTableBody?.querySelector(`tr[data-request-id="${form.dataset.editingId}"]`);
    const dateCell = row?.children[4];
    if (dateCell && dateValue) {
      const parsedDate = new Date(dateValue);
      if (!Number.isNaN(parsedDate.getTime())) dateCell.textContent = parsedDate.toLocaleString('ar-EG', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
  }, true);

  // Stage 5: creation/deletion use the same guarded AJAX path as review actions.
  const requestCreateForm = document.querySelector('#requestCreateModal form');
  // The conditional fields are created by the unified request form script.
  // Keep this legacy hook disabled so a second, conflicting field set cannot
  // be injected into the modal.
  if (false && requestCreateForm && !requestCreateForm.querySelector('[name="duration_days"]')) {
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
  document.querySelectorAll('tr[data-request-date]').forEach((row) => {
    const dateCell = row.children[4];
    if (dateCell && row.dataset.requestDate) {
      const parsedDate = new Date(row.dataset.requestDate);
      if (!Number.isNaN(parsedDate.getTime())) dateCell.textContent = parsedDate.toLocaleString('ar-EG', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
  });
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
  // Edit requests in-place. The edit link fetches the record, fills the
  // type-specific fields, and opens the existing modal without navigation.
  document.addEventListener('click', async (event) => {
    const link = event.target.closest?.('a[href*="?edit="]');
    if (!link) return;
    event.preventDefault();
    const id = new URL(link.href, window.location.href).searchParams.get('edit');
    if (!id) return;
    const form = document.querySelector('#requestCreateModal form');
    if (!form) return;
    try {
      const response = await fetch(`${requestBaseUrl}/${id}/edit-data`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error('تعذر تحميل بيانات الطلب.');
      const data = await response.json();
      const payload = data.payload || {};
      form.dataset.editing = '1';
      form.dataset.editingId = String(data.id);
      form.action = `${requestBaseUrl}/${data.id}`;
      let method = form.querySelector('input[name="_method"]');
      if (!method) { method = document.createElement('input'); method.type = 'hidden'; method.name = '_method'; form.prepend(method); }
      method.value = 'PUT';
      const lookup = form.querySelector('[name="employee_lookup"]');
      const employee = form.querySelector('input[name="employee_id"]');
      if (lookup) lookup.value = data.employee_name || '';
      if (employee) employee.value = data.employee_id || '';
      const type = form.querySelector('[name="request_type_id"]');
      if (type) { type.disabled = false; type.value = data.request_type_id || ''; type.dispatchEvent(new Event('change', { bubbles: true })); type.disabled = true; }
      form.querySelector('[name="title"]')?.setAttribute('value', data.title || '');
      const title = form.querySelector('[name="title"]'); if (title) title.value = data.title || '';
      const details = form.querySelector('[name="details"]'); if (details) details.value = data.details || '';
      const set = (name, value) => { const field = form.querySelector(`[name="${name}"]`); if (field && value !== undefined && value !== null) field.value = value; };
      set('advance_amount', payload.amount); set('overtime_request_date', payload.request_date); set('overtime_period', payload.overtime_period);
      set('day_overtime_hours', payload.overtime_period === 'day' ? payload.hours : ''); set('night_overtime_hours', payload.overtime_period === 'night' ? payload.hours : '');
      set('financial_request_date', payload.request_date); set('financial_amount', payload.amount); set('other_request_date', payload.request_date); set('impact_amount', payload.amount); set('impact_type', payload.impact_type);
      if (['permission', 'mission'].includes(@json($requestTypes->mapWithKeys(fn ($item) => [$item->id => $item->source_key]))[data.request_type_id])) {
        form.querySelector('[data-excuse-card] [name="request_date"]').value = payload.request_date || '';
      }
      set('permission_from_time', payload.from_time); set('permission_to_time', payload.to_time); set('from_time', payload.from_time); set('to_time', payload.to_time); set('request_date', payload.request_date); set('start_date', payload.start_date); set('end_date', payload.end_date); set('duration_days', payload.days); set('duration_hours', payload.hours); set('addition_amount', payload.addition_amount); set('deduction_amount', payload.deduction_amount);
      form.querySelector('[name="advance_amount"]')?.dispatchEvent(new Event('input', { bubbles: true }));
      form.querySelector('.advance-existing-notice')?.setAttribute('hidden', 'hidden');
      form.querySelector('.advance-existing-confirm')?.setAttribute('hidden', 'hidden');
      const heading = document.getElementById('requestCreateTitle'); if (heading) heading.textContent = 'تعديل الطلب';
      const submit = form.querySelector('button[type="submit"]'); if (submit) submit.textContent = 'حفظ التعديل';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('requestCreateModal')).show();
    } catch (error) { window.showToast?.(error.message || 'تعذر تحميل بيانات الطلب.'); }
  });

  // Return the form to create mode after an in-place edit is saved.
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.closest('#requestCreateModal')) return;
    if (form.action.startsWith(requestBaseUrl + '/') && event.detail?.request?.id) {
      // Preserve the edited id before resetting the form; the row updater
      // runs in the following success handler.
      if (form.dataset.editingId) form.dataset.lastEditedId = form.dataset.editingId;
      form.action = requestBaseUrl;
      form.dataset.editing = '0';
      delete form.dataset.editingId;
      form.querySelector('input[name="_method"]')?.remove();
      form.querySelector('[name="request_type_id"]')?.removeAttribute('disabled');
      const heading = document.getElementById('requestCreateTitle'); if (heading) heading.textContent = 'طلب HR جديد';
      const submit = form.querySelector('button[type="submit"]'); if (submit) submit.textContent = 'إرسال الطلب';
    }
  });
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.closest('#requestCreateModal')) {
      const item = event.detail?.request || {};
      const editedRequestId = form.dataset.editingId || form.dataset.lastEditedId;
      if (editedRequestId && requestTableBody) {
        const row = requestTableBody.querySelector(`tr[data-request-id="${editedRequestId}"]`);
        if (row) {
          const titleCell = row.children[0];
          const titleLink = titleCell?.querySelector('a');
          const titleValue = form.querySelector('[name="title"]')?.value || item?.title || '';
          const detailsValue = form.querySelector('[name="details"]')?.value || item?.details || '';
          if (titleLink) titleLink.textContent = titleValue;
          const details = titleCell?.querySelector('.text-muted');
          if (detailsValue && details) details.textContent = detailsValue;
          const editedValue = item?.value || item?.display_value || form.querySelector('[name="advance_amount"], [name="impact_amount"], [name="addition_amount"], [name="deduction_amount"]')?.value;
          const valueCell = row.querySelector('[data-request-value-cell]') || row.children[3];
          if (valueCell && editedValue !== undefined) valueCell.textContent = editedValue || '—';
          if (row.children[3] && editedValue !== undefined) row.children[3].textContent = editedValue || '—';
          if (row.children[3] && item?.value !== undefined) row.children[3].textContent = item.value || '—';
        }
        const editedDate = item?.request_date || item?.created_at;
        const editedRow = requestTableBody?.querySelector(`tr[data-request-id="${editedRequestId}"]`);
        const editedDateCell = editedRow?.querySelector('[data-request-date-cell]') || editedRow?.children[4];
        if (editedDateCell && editedDate) editedDateCell.textContent = requestDate(editedDate);
        delete form.dataset.lastEditedId;
      } else if (item && requestTableBody) {
        requestTableBody.querySelector('td[colspan]')?.closest('tr')?.remove();
        requestTableBody.insertAdjacentHTML('afterbegin', requestRow(item));
        const addedRow = requestTableBody.querySelector(`tr[data-request-id="${Number(item.id)}"]`);
        if (addedRow) {
           // Normalize the legacy client row to request, employee, type,
           // value, date, actions. The old template still emits a status cell.
           if (addedRow.children.length === 6) {
             const valueCell = document.createElement('td');
             valueCell.dataset.requestValueCell = 'true';
             valueCell.textContent = item.value || '—';
             addedRow.insertBefore(valueCell, addedRow.children[3]);
             addedRow.children[5]?.remove();
           }
           if (addedRow.children[4]) addedRow.children[4].textContent = requestDate(item.request_date || item.created_at);
          // Remove a legacy status cell if an old client-side row template
          // was still used by the browser.
          const legacyStatusCell = addedRow.children[4];
          if (legacyStatusCell?.querySelector('.badge-soft') && !legacyStatusCell.querySelector('form, a, button')) legacyStatusCell.remove();
          const actions = addedRow.lastElementChild;
          if (actions) {
            const edit = @json(auth()->user()?->hasPermission('requests.create')) ? `<a class="btn btn-sm btn-outline-primary" href="?edit=${Number(item.id)}" title="تعديل"><i class="bi bi-pencil"></i></a>` : '';
            const remove = @json(auth()->user()?->hasPermission('requests.delete')) ? `<form method="POST" action="${requestBaseUrl}/${Number(item.id)}"><input type="hidden" name="_token" value="${escapeRequestHtml(document.querySelector('meta[name=\"csrf-token\"]')?.content || '')}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>` : '';
            actions.innerHTML = `<div class="d-flex gap-1 align-items-center">${edit}${remove}</div>`;
          }
        }
        wireHrRequestAjaxForms(requestTableBody);
      }
      bootstrap.Modal.getInstance(document.getElementById('requestCreateModal'))?.hide();
      form.reset();
      delete form.dataset.editingId;
      const cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('create');
      cleanUrl.searchParams.delete('edit');
      window.history.replaceState({}, document.title, cleanUrl.pathname + (cleanUrl.search ? `?${cleanUrl.searchParams.toString()}` : '') + cleanUrl.hash);
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
  // The unified conditional-fields script above owns overtime rendering.
  // This older duplicate used to replace its fields after initialization.
  return;
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
  if (id) form.action = @json(url('/requests')) + '/' + id;
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
// Permission windows have a position within the shift, not only a duration.
(() => {
  const form = document.querySelector('#requestCreateModal form');
  const type = form?.querySelector('[name="request_type_id"]');
  if (!form || !type) return;
  const sources = @json($requestTypes->mapWithKeys(fn ($item) => [$item->id => $item->source_key]));
  const card = document.createElement('div');
  card.className = 'row g-2 mt-2';
  card.dataset.excuseCard = 'true';
  card.innerHTML = '<div class="col-12 text-muted small">الإذن يغطي وقته المحدد فقط. المأمورية تغطي أيام العمل داخل الفترة.</div><div class="col-12"><label class="form-label">تاريخ الطلب</label><input type="date" name="request_date" class="form-control"></div><div class="col-6" data-mission><label class="form-label">من تاريخ</label><input type="date" name="start_date" class="form-control"></div><div class="col-6" data-mission><label class="form-label">إلى تاريخ</label><input type="date" name="end_date" class="form-control"></div><div class="col-6" data-permission><label class="form-label">من الساعة</label><input type="time" name="permission_from_time" class="form-control" dir="ltr"></div><div class="col-6" data-permission><label class="form-label">إلى الساعة</label><input type="time" name="permission_to_time" class="form-control" dir="ltr"></div>';
  form.querySelector('.modal-body').append(card);
  const sync = () => {
    const source = sources[type.value];
    const active = ['permission', 'mission'].includes(source);
    card.hidden = !active;
    card.querySelectorAll('input').forEach(input => {
      const forPermission = input.closest('[data-permission]');
      const forMission = input.closest('[data-mission]');
      const enabled = active && (!forPermission || source === 'permission') && (!forMission || source === 'mission');
      input.disabled = !enabled;
      input.required = enabled && !forMission;
      if (forPermission || forMission) input.parentElement.hidden = !enabled;
    });
    if (active && !card.querySelector('[name="request_date"]').value) card.querySelector('[name="request_date"]').value = @json(today()->toDateString());
  };
  type.addEventListener('change', sync);
  document.getElementById('requestCreateModal')?.addEventListener('shown.bs.modal', () => {
    sync();
    const initialEditing = @json($editingRequestData);
    const editing = initialEditing && String(initialEditing.id) === String(form.dataset.editingId) ? initialEditing : null;
    if (editing && ['permission', 'mission'].includes(editing.request_type_source)) {
      card.querySelectorAll('input').forEach(input => input.value = editing.payload?.[input.name.replace('permission_', '')] || '');
    }
  });
  sync();
})();
</script>
@endpush
