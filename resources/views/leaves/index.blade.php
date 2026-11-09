@extends('layouts.app')
@section('title', 'الإجازات')
@section('content')
<div class="page-head"><div><h1>الإجازات</h1><p>كل إجازة تُسجّل مؤكدة فورًا وتنعكس على الملخص والرصيد، دون خطوة اعتماد.</p></div><div class="d-flex gap-2 flex-wrap">@if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{ route('master.index',['type'=>'leavetypes']) }}"><i class="bi bi-gear"></i> إعدادات أنواع الإجازات</a>@endif @if(auth()->user()?->hasPermission('leaves.view'))<a class="btn btn-light" href="{{ route('leaves.opening.index') }}"><i class="bi bi-wallet2"></i> الأرصدة الافتتاحية والترحيل</a>@endif @if(auth()->user()?->hasPermission('leaves.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="bi bi-plus-lg"></i> طلب إجازة</button>@endif</div></div>
<div class="stat-grid compact">@foreach([['مؤكدة هذا الشهر',$approvedThisMonth,'green','check-circle'],['أيام الإجازات المؤكدة',$totalDays,'blue','calendar2-check'],['في إجازة اليوم',$onLeaveToday,'purple','person-walking'],['ملغاة هذا الشهر',$rejectedThisMonth,'red','x-circle']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ format_number($stat[1]) }}</b><small>حسب البيانات الحالية</small></div></div>@endforeach</div>
<form class="filter-bar d-flex flex-wrap gap-2" method="GET"><select name="employee_id" class="form-select"><option value="">كل الموظفين</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->full_name }}</option>@endforeach</select><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><select name="leave_type_id" class="form-select"><option value="">كل الأنواع</option>@foreach($leaveTypes as $type)<option value="{{ $type->id }}" @selected(request('leave_type_id') == $type->id)>{{ $type->name }}</option>@endforeach</select><select name="status" class="form-select"><option value="">كل الحالات</option>@foreach(['Approved'=>'مؤكدة','Rejected'=>'ملغاة'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="من تاريخ"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="إلى تاريخ"><button class="btn btn-primary"><i class="bi bi-funnel"></i> تصفية</button><a class="btn btn-light" href="{{ route('leaves.index') }}">مسح</a></form>
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الموظف</th><th>النوع</th><th>الأجر</th><th>من</th><th>إلى</th><th>الأيام</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>@forelse($leaves as $leave)<tr><td><div class="employee-cell"><span class="employee-avatar">@if($leave->employee?->profile_photo_url)<img src="{{ $leave->employee->profile_photo_url }}" alt="">@else{{ $leave->employee?->initials }}@endif</span><div><a class="employee-link fw-bold" href="{{ route('employees.show',$leave->employee) }}">{{ $leave->employee?->full_name }}</a><small class="d-block text-muted">#{{ $leave->employee?->employee_code }}</small></div></div></td><td>{{ $leave->leaveType?->name ?? '—' }}</td><td><span class="badge-soft {{ $leave->leaveType?->is_paid ? 'success' : 'muted' }}">{{ $leave->leaveType?->is_paid ? 'بأجر' : 'بدون أجر' }}</span></td><td>{{ $leave->start_date?->format('d/m/Y') }}</td><td>{{ $leave->end_date?->format('d/m/Y') }}</td><td>{{ format_number($leave->days) }}</td><td><span class="badge-soft {{ $leave->status_badge_class }}">{{ in_array($leave->status, \App\Models\Leave::inactiveStatuses(), true) ? 'ملغاة' : 'مؤكدة' }}</span></td><td><div class="d-flex gap-1 flex-wrap">@if(auth()->user()?->hasPermission('leaves.edit'))<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editLeave{{ $leave->id }}"><i class="bi bi-pencil"></i></button>@endif  @if(auth()->user()?->hasPermission('leaves.delete'))<form method="POST" action="{{ route('leaves.destroy',$leave) }}" onsubmit="return confirm('هل تريد حذف طلب الإجازة؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>@endif</div></td></tr>@empty<tr><td colspan="8" class="text-center py-5">لا توجد طلبات إجازة بهذه المعايير.</td></tr>@endforelse</tbody></table></div></div>
<x-pagination-controls :paginator="$leaves" />
<div class="modal fade" id="leaveModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('leaves.store') }}">@csrf<div class="modal-header"><h5>طلب إجازة جديد</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><label class="form-label">نوع الإجازة</label><select name="leave_type_id" class="form-select mb-3" required>@foreach($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'بأجر' : 'بدون أجر' }})</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">من</label><input type="date" name="start_date" class="form-control" required></div><div class="col-6"><label class="form-label">إلى</label><input type="date" name="end_date" class="form-control" required></div></div><label class="form-label mt-3">السبب</label><textarea name="reason" class="form-control" rows="3"></textarea></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ الطلب</button></div></form></div></div></div>
@foreach($leaves as $leave)<div class="modal fade" id="editLeave{{ $leave->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('leaves.update',$leave) }}">@csrf @method('PUT')<div class="modal-header"><h5>تعديل طلب الإجازة</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-2" required>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected($leave->employee_id === $employee->id)>{{ $employee->full_name }}</option>@endforeach</select><label class="form-label">نوع الإجازة</label><select name="leave_type_id" class="form-select mb-2" required>@foreach($leaveTypes as $type)<option value="{{ $type->id }}" @selected($leave->leave_type_id === $type->id)>{{ $type->name }}</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">من</label><input type="date" name="start_date" class="form-control" value="{{ $leave->start_date?->format('Y-m-d') }}" required></div><div class="col-6"><label class="form-label">إلى</label><input type="date" name="end_date" class="form-control" value="{{ $leave->end_date?->format('Y-m-d') }}" required></div></div><label class="form-label mt-2">الحالة</label><select name="status" class="form-select mb-2">@foreach(['Approved'=>'مؤكدة','Rejected'=>'ملغاة'] as $value=>$label)<option value="{{ $value }}" @selected((in_array($leave->status, \App\Models\Leave::inactiveStatuses(), true) ? 'Rejected' : 'Approved') === $value)>{{ $label }}</option>@endforeach</select><label class="form-label">السبب</label><textarea name="reason" class="form-control">{{ $leave->reason }}</textarea><label class="form-label mt-2">سبب الإلغاء</label><textarea name="rejection_reason" class="form-control">{{ $leave->rejection_reason }}</textarea></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>@endforeach
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const canApprove = @json(auth()->user()?->hasPermission('leaves.approve'));
  const askEarlyGrant = (message) => new Promise((resolve) => {
    document.getElementById('earlyGrantConfirm')?.remove();
    const wrap = document.createElement('div');
    wrap.innerHTML = '<div class="modal fade" id="earlyGrantConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-triangle text-warning"></i> تنبيه قبل الحفظ</h5></div><div class="modal-body"><p class="mb-2 early-grant-reason"></p><p class="mb-0 text-muted small">لو وافقت تُسجَّل الإجازة كمنح استثنائي باسمك وبتاريخ اليوم.</p></div><div class="modal-footer"><button type="button" class="btn btn-light" data-answer="no">إلغاء</button><button type="button" class="btn btn-primary" data-answer="yes">موافق</button></div></div></div></div>';
    const modalEl = wrap.firstElementChild;
    modalEl.querySelector('.early-grant-reason').textContent = message;
    document.body.appendChild(modalEl);
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    let answer = false;
    modalEl.addEventListener('click', (event) => { const b = event.target.closest('[data-answer]'); if (!b) return; answer = b.dataset.answer === 'yes'; modal.hide(); });
    modalEl.addEventListener('hidden.bs.modal', () => { modalEl.remove(); resolve(answer); });
    modal.show();
  });
  document.addEventListener('masar:error', async (event) => {
    const form = event.target;
    const errors = event.detail?.payload?.errors;
    if (!(form instanceof HTMLFormElement) || !errors?.early_grant_available || !canApprove) return;
    if (form.querySelector('input[name="is_early_grant"]')) return;
    event.preventDefault();
    const message = (errors.start_date || [])[0] || 'الموظف لم يستوفِ مدة الاستحقاق.';
    const parentEl = form.closest('.modal');
    const parent = parentEl ? bootstrap.Modal.getInstance(parentEl) : null;
    if (parentEl && parentEl.classList.contains('show')) {
      await new Promise((done) => { parentEl.addEventListener('hidden.bs.modal', done, { once: true }); parent.hide(); });
    }
    const confirmed = await askEarlyGrant(message + ' هل تريد منح الإجازة استثنائيًا؟');
    if (parent) { await new Promise((done) => { parentEl.addEventListener('shown.bs.modal', done, { once: true }); parent.show(); }); }
    if (!confirmed) return;
    const add = (name, value) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; input.dataset.earlyGrant = '1'; form.appendChild(input); };
    add('is_early_grant', '1'); add('early_wait_only', '1'); add('early_grant_reason', message);
    const clear = () => form.querySelectorAll('input[data-early-grant]').forEach((input) => input.remove());
    form.addEventListener('masar:success', clear, { once: true });
    form.addEventListener('masar:error', clear, { once: true });
    form.requestSubmit();
  });
});
</script>

@endpush
@push('scripts')
<script>
  const leaveBaseUrl = @json(url('/leaves'));
  const leaveTableBody = document.querySelector('.table-panel table tbody');
  const canEditLeave = @json(auth()->user()?->hasPermission('leaves.edit'));
  const canApproveLeave = @json(auth()->user()?->hasPermission('leaves.approve'));
  const canDeleteLeave = @json(auth()->user()?->hasPermission('leaves.delete'));
  const leaveCache = @json($leaves->getCollection()->keyBy('id'));
  const leaveEmployees = @json($employees->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->full_name]));
  const leaveTypesData = @json($leaveTypes->map(fn ($type) => ['id' => $type->id, 'name' => $type->name]));
  const escapeLeaveHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const leaveStatus = (status) => ['Rejected', 'Cancelled', 'Canceled', 'مرفوض', 'مرفوضة', 'ملغاة', 'ملغي', 'ملغى'].includes(status) ? ['ملغاة', 'danger'] : ['مؤكدة', 'success'];
  const leaveDate = (value) => { const parts = String(value || '').slice(0, 10).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : '—'; };
  const leaveInitials = (name) => String(name || '—').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('');
  const leaveRow = (leave) => {
    leaveCache[leave.id] = leave;
    const employee = leave.employee || {};
    const type = leave.leave_type || leave.leaveType || {};
    const [statusLabel, statusClass] = leaveStatus(leave.status);
    const id = Number(leave.id);
    const approve = '';
    const edit = canEditLeave ? `<button type="button" class="btn btn-sm btn-outline-primary leave-edit-ajax" data-leave-id="${id}" title="تعديل"><i class="bi bi-pencil"></i></button>` : '';
    const remove = canDeleteLeave ? `<form method="POST" action="${leaveBaseUrl}/${id}" onsubmit="return confirm('هل تريد حذف طلب الإجازة؟')"><input type="hidden" name="_token" value="${escapeLeaveHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>` : '';
    return `<tr data-leave-id="${id}"><td><div class="employee-cell"><span class="employee-avatar">${escapeLeaveHtml(leaveInitials(employee.full_name))}</span><div><a class="employee-link fw-bold" href="${escapeLeaveHtml(employee.id ? `{{ url('/employees') }}/${employee.id}` : '#')}">${escapeLeaveHtml(employee.full_name)}</a><small class="d-block text-muted">#${escapeLeaveHtml(employee.employee_code)}</small></div></div></td><td>${escapeLeaveHtml(type.name)}</td><td><span class="badge-soft ${type.is_paid ? 'success' : 'muted'}">${type.is_paid ? 'بأجر' : 'بدون أجر'}</span></td><td>${leaveDate(leave.start_date)}</td><td>${leaveDate(leave.end_date)}</td><td>${escapeLeaveHtml(leave.days)}</td><td><span class="badge-soft ${statusClass}">${statusLabel}</span></td><td><div class="d-flex gap-1 flex-wrap">${edit}${approve}${remove}</div></td></tr>`;
  };
  const proofFields = (form) => {
    if (form.querySelector('[name="document"]')) return;
    form.enctype = 'multipart/form-data';
    const id = Number(form.closest('.modal')?.id?.replace('editLeave',''));
    const leave = leaveCache[id] || {};
    const area = document.createElement('div'); area.className='mt-3 leave-proof-fields';
    area.innerHTML = `<label class="form-label">تاريخ الولادة (أو المتوقع، لإجازة الوضع)</label><input type="date" name="birth_date" class="form-control mb-2" value="${escapeLeaveHtml(String(leave.birth_date || '').slice(0,10))}"><label class="form-label">مستند الإجازة (حتى 10 ميجابايت)</label><input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><small class="text-muted">المستند مطلوب عندما تنص سياسة الشركة عليه. إجازة الامتحانات تحتاج إثباتًا وإخطارًا قبل البداية بـ10 أيام افتراضيًا.</small>${leave.document_path ? `<a class="d-block mt-1" target="_blank" href="${leaveBaseUrl}/${id}/document">عرض المستند المحفوظ</a>` : ''}`;
    form.querySelector('.modal-body').appendChild(area);
  };
  const wireLeaveAjaxForms = (root = document) => {
    root.querySelectorAll('#leaveModal form, .modal[id^="editLeave"] form').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحفظ'; proofFields(form); });
    root.querySelectorAll('form[action*="/leaves/"][action$="/approve"]').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الاعتماد'; });
    root.querySelectorAll('form[action*="/leaves/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => { const form = methodInput.form; if (!form) return; form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحذف'; form.dataset.ajaxConfirm = 'هل تريد حذف طلب الإجازة؟'; form.removeAttribute('onsubmit'); });
  };
  wireLeaveAjaxForms();
  document.querySelectorAll('tbody tr').forEach((row) => { const action = row.querySelector('form[action*="/leaves/"]')?.action; const match = action?.match(/\/leaves\/(\d+)/); if (match) row.dataset.leaveId = match[1]; });
  const upsertLeaveRow = (leave) => { if (!leaveTableBody || !leave?.id) return; const oldRow = leaveTableBody.querySelector(`tr[data-leave-id="${leave.id}"]`); if (oldRow) oldRow.outerHTML = leaveRow(leave); else { leaveTableBody.querySelector('td[colspan]')?.closest('tr')?.remove(); leaveTableBody.insertAdjacentHTML('afterbegin', leaveRow(leave)); } wireLeaveAjaxForms(leaveTableBody); };
  const ensureLeaveEditModal = (leave) => {
    const id = Number(leave.id);
    if (document.getElementById(`editLeave${id}`)) { bootstrap.Modal.getOrCreateInstance(document.getElementById(`editLeave${id}`)).show(); return; }
    const employeeOptions = leaveEmployees.map((employee) => `<option value="${employee.id}" ${Number(employee.id) === Number(leave.employee_id) ? 'selected' : ''}>${escapeLeaveHtml(employee.name)}</option>`).join('');
    const typeOptions = leaveTypesData.map((type) => `<option value="${type.id}" ${Number(type.id) === Number(leave.leave_type_id) ? 'selected' : ''}>${escapeLeaveHtml(type.name)}</option>`).join('');
    const modal = document.createElement('div');
    modal.className = 'modal fade'; modal.id = `editLeave${id}`; modal.tabIndex = -1;
    modal.innerHTML = `<div class="modal-dialog"><div class="modal-content"><form method="POST" action="${leaveBaseUrl}/${id}"><input type="hidden" name="_token" value="${escapeLeaveHtml(csrfToken)}"><input type="hidden" name="_method" value="PUT"><div class="modal-header"><h5>تعديل طلب الإجازة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-2" required>${employeeOptions}</select><label class="form-label">نوع الإجازة</label><select name="leave_type_id" class="form-select mb-2" required>${typeOptions}</select><div class="row g-2"><div class="col-6"><label class="form-label">من</label><input type="date" name="start_date" class="form-control" value="${String(leave.start_date || '').slice(0, 10)}" required></div><div class="col-6"><label class="form-label">إلى</label><input type="date" name="end_date" class="form-control" value="${String(leave.end_date || '').slice(0, 10)}" required></div></div><label class="form-label mt-2">الحالة</label><select name="status" class="form-select mb-2"><option value="Approved">مؤكدة</option><option value="Rejected">ملغاة</option></select><label class="form-label">السبب</label><textarea name="reason" class="form-control">${escapeLeaveHtml(leave.reason)}</textarea><label class="form-label mt-2">سبب الإلغاء</label><textarea name="rejection_reason" class="form-control">${escapeLeaveHtml(leave.rejection_reason)}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div>`;
    document.body.appendChild(modal); modal.querySelector('[name="status"]').value = leaveStatus(leave.status)[0] === 'ملغاة' ? 'Rejected' : 'Approved'; wireLeaveAjaxForms(modal); bootstrap.Modal.getOrCreateInstance(modal).show();
  };
  document.addEventListener('click', (event) => { const button = event.target.closest('.leave-edit-ajax'); if (!button) return; event.preventDefault(); ensureLeaveEditModal(leaveCache[button.dataset.leaveId]); });
  document.addEventListener('masar:success', (event) => {
    const form = event.target; if (!(form instanceof HTMLFormElement)) return;
    if (form.closest('#leaveModal') || form.closest('.modal[id^="editLeave"]')) { const leave = event.detail?.leave; upsertLeaveRow(leave); const modal = form.closest('.modal'); bootstrap.Modal.getInstance(modal)?.hide(); if (form.closest('#leaveModal')) form.reset(); }
    if (form.querySelector('input[name="_method"][value="DELETE"]')) form.closest('tr')?.remove();
  });
</script>
@endpush
@endsection
