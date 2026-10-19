@extends('layouts.app')
@section('title', 'الطلبات')

@section('content')
<div class="page-head">
    <div>
        <h1>طلبات الموارد البشرية</h1>
        <p>طلبات السلف والأذونات والمأموريات وطلبات المستندات والطلبات الأخرى، مرتبطة مباشرة بقاعدة البيانات.</p>
    </div>
    @if(auth()->user()?->hasPermission('requests.create'))
        <a href="#requestCreateModal" class="btn btn-primary" data-bs-toggle="modal"><i class="bi bi-plus-lg"></i> طلب جديد</a>
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
const requestCardData=@json($requestCardDetails);const requestKeys=['all','pending','approved','rejected'];const requestTitles=['إجمالي الطلبات','الطلبات المعلقة','الطلبات المعتمدة','الطلبات المرفوضة'];document.querySelectorAll('[data-request-kpi]').forEach(card=>card.addEventListener('click',()=>{const i=Number(card.dataset.requestKpi);const list=requestCardData[requestKeys[i]]||[];document.getElementById('requestCardTitle').textContent=requestTitles[i];document.getElementById('requestCardBody').innerHTML=list.length?list.map(item=>'<div class="employee-kpi-detail-row"><span class="employee-kpi-detail-avatar">'+(item.employee?.initials||item.employee?.full_name?.slice(0,1)||'—')+'</span><div><a href="{{ url('/employees') }}/'+(item.employee_id||'')+'">'+(item.employee?.full_name||'—')+'</a><small>'+item.title+' · '+(item.status||'')+'</small></div></div>').join(''):'<div class="text-muted text-center p-4">لا توجد طلبات.</div>';bootstrap.Modal.getOrCreateInstance(document.getElementById('requestCardDetails')).show();}));
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
            <form method="POST" action="{{ route('requests.store') }}">
                @csrf
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
      if (item && requestTableBody) { requestTableBody.querySelector('td[colspan]')?.closest('tr')?.remove(); requestTableBody.insertAdjacentHTML('afterbegin', requestRow(item)); wireHrRequestAjaxForms(requestTableBody); }
      bootstrap.Modal.getInstance(document.getElementById('requestCreateModal'))?.hide(); form.reset();
    }
    if (form.querySelector('input[name="_method"][value="DELETE"]') && form.action.includes('/requests/')) form.closest('tr')?.remove();
  });
</script>
@endpush

@if(request()->boolean('create') || $errors->hasAny(['employee_id', 'request_type_id', 'title', 'details']))
@push('scripts')
<script>document.addEventListener('DOMContentLoaded',function(){const m=document.getElementById('requestCreateModal');if(m){bootstrap.Modal.getOrCreateInstance(m).show();}});</script>
@endpush
@endif
