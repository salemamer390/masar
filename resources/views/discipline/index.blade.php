@extends('layouts.app')
@section('title',__('الجزاءات والمخالفات'))
@section('content')
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET"><select name="employee_id" class="form-select"><option value="">{{ __('كل الموظفين') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->full_name }}</option>@endforeach</select><select name="department_id" class="form-select"><option value="">{{ __('كل الأقسام') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><select name="status" class="form-select"><option value="">{{ __('كل الحالات') }}</option><option value="Approved" @selected(request('status') === 'Approved')>{{ __('مؤكد') }}</option></select><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="{{ __('من تاريخ') }}"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="{{ __('إلى تاريخ') }}"><button class="btn btn-primary"><i class="bi bi-funnel"></i> {{ __('عرض') }}</button><a class="btn btn-light" href="{{ route('discipline.index') }}">{{ __('مسح') }}</a></form>
<div class="page-head"><div><h1>{{ __('الجزاءات والمخالفات') }}</h1><p>{{ __('تسجيل الإجراءات التأديبية ومتابعة حالتها وتأثيرها المالي.') }}</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{route('master.index',['type'=>'penalties'])}}"><i class="bi bi-gear"></i> {{ __('إعدادات أنواع الجزاءات') }}</a>@endif @if(auth()->user()?->hasPermission('penalties.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#penaltyModal"><i class="bi bi-plus-lg"></i> {{ __('تسجيل إجراء') }}</button>@endif</div></div>
<div class="panel table-panel"><table class="table align-middle"><thead><tr><th>{{ __('الموظف') }}</th><th>{{ __('نوع الإجراء') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('السبب') }}</th><th>{{ __('أيام الخصم') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('الإجراءات') }}</th></tr></thead><tbody>@forelse($penalties as $p)<tr><td><a class="employee-link fw-bold" href="{{route('employees.show',$p->employee)}}">{{$p->employee?->full_name}}</a></td><td>{{$p->penaltyType?->name??'—'}}</td><td>{{$p->penalty_date?->format('d/m/Y')}}</td><td>{{$p->reason}}</td><td>{{format_number($p->deduction_days ?? $p->penaltyType?->default_deduction_days ?? 0)}}</td><td><span class="badge-soft {{$p->status_badge_class}}">{{ in_array($p->status, \App\Models\Penalty::inactiveStatuses(), true) ? 'ملغى' : 'مؤكد' }}</span></td><td><div class="d-flex gap-1 flex-wrap"> @if(auth()->user()?->hasPermission('penalties.edit'))<button class="btn btn-sm btn-outline-primary penalty-edit-btn" data-bs-toggle="modal" data-bs-target="#editPenaltyModal" data-id="{{ $p->id }}" data-employee-id="{{ $p->employee_id }}" data-type-id="{{ $p->penalty_type_id }}" data-date="{{ $p->penalty_date?->format('Y-m-d') }}" data-reason="{{ $p->reason }}" data-details="{{ $p->action }}" data-amount="{{ $p->amount }}" data-days="{{ $p->deduction_days }}" title="{{ __('تعديل') }}"><i class="bi bi-pencil"></i></button>@endif @if(auth()->user()?->hasPermission('penalties.delete'))<form method="POST" action="{{ route('discipline.destroy',$p) }}" onsubmit="return confirm('هل تريد حذف هذا الإجراء؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="{{ __('حذف') }}"><i class="bi bi-trash"></i></button></form>@endif</div></td></tr>@empty<tr><td colspan="7" class="text-center py-5">{{ __('لا توجد جزاءات.') }}</td></tr>@endforelse</tbody></table></div>
<x-pagination-controls :paginator="$penalties" />
<div class="modal fade" id="penaltyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('discipline.store')}}">@csrf<div class="modal-header"><h5>{{ __('تسجيل جزاء أو مخالفة') }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" class="form-select mb-3" required>@foreach($employees as $e)<option value="{{$e->id}}">{{$e->full_name}}</option>@endforeach</select><label class="form-label">{{ __('نوع الإجراء') }}</label><select name="penalty_type_id" class="form-select mb-3">@foreach($penaltyTypes as $t)<option value="{{$t->id}}">{{$t->name}}</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">{{ __('التاريخ') }}</label><input type="date" name="penalty_date" class="form-control" value="{{date('Y-m-d')}}" required></div><div class="col-6"><label class="form-label">{{ __('أيام الخصم') }} <small class="text-muted">{{ __('اختياري') }}</small></label><input type="number" step="0.5" min="0" name="deduction_days" class="form-control" placeholder="{{ __('حسب النوع تلقائياً') }}"></div></div><label class="form-label mt-3">{{ __('سبب المخالفة') }}</label><textarea name="reason" class="form-control" required></textarea><label class="form-label mt-3">{{ __('التفاصيل') }}</label><textarea name="details" class="form-control"></textarea></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ للمراجعة') }}</button></div></form></div></div></div>
<div class="modal fade" id="editPenaltyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" id="editPenaltyForm">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('تعديل جزاء أو مخالفة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" id="editPenaltyEmployee" class="form-select mb-3" required>@foreach($employees as $e)<option value="{{$e->id}}">{{$e->full_name}}</option>@endforeach</select><label class="form-label">{{ __('نوع الإجراء') }}</label><select name="penalty_type_id" id="editPenaltyType" class="form-select mb-3" required>@foreach($penaltyTypes as $t)<option value="{{$t->id}}">{{$t->name}}</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">{{ __('التاريخ') }}</label><input id="editPenaltyDate" type="date" name="penalty_date" class="form-control" required></div><div class="col-6"><label class="form-label">{{ __('أيام الخصم') }}</label><input id="editPenaltyDays" type="number" step="0.5" min="0" name="deduction_days" class="form-control"></div><div class="col-12"><label class="form-label">{{ __('قيمة الخصم') }}</label><input id="editPenaltyAmount" type="number" step="0.01" min="0" name="amount" class="form-control"></div></div><label class="form-label mt-3">{{ __('سبب المخالفة') }}</label><textarea id="editPenaltyReason" name="reason" class="form-control" required></textarea><label class="form-label mt-3">{{ __('التفاصيل') }}</label><textarea id="editPenaltyDetails" name="details" class="form-control"></textarea><p class="small text-muted mt-3 mb-0">{{ __('سيُعاد الإجراء للمراجعة بعد الحفظ.') }}</p></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ التعديل') }}</button></div></form></div></div></div>
@push('scripts')<script>document.querySelectorAll('.penalty-edit-btn').forEach(button=>button.addEventListener('click',()=>{const d=button.dataset;const form=document.getElementById('editPenaltyForm');form.action=`{{ url('/discipline/penalties') }}/${d.id}`;document.getElementById('editPenaltyEmployee').value=d.employeeId;document.getElementById('editPenaltyType').value=d.typeId;document.getElementById('editPenaltyDate').value=d.date;document.getElementById('editPenaltyReason').value=d.reason;document.getElementById('editPenaltyDetails').value=d.details;document.getElementById('editPenaltyAmount').value=d.amount;document.getElementById('editPenaltyDays').value=d.days;}));</script>@endpush
@push('scripts')
<script>
  const penaltyBaseUrl = @json(url('/discipline/penalties'));
  const penaltyTableBody = document.querySelector('.table-panel table tbody');
  const canEditPenalty = @json(auth()->user()?->hasPermission('penalties.edit'));
  const canDeletePenalty = @json(auth()->user()?->hasPermission('penalties.delete'));
  const escapePenaltyHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const penaltyStatus = (status) => ['Rejected','Cancelled','Canceled','مرفوض','مرفوضة','ملغى','ملغي','ملغاة'].includes(status) ? ['ملغى','danger'] : ['مؤكد','success'];
  const penaltyDate = (value) => { const parts = window.masarYmd(value).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : '—'; };
  const penaltyRow = (penalty) => {
    const employee = penalty.employee || {};
    const type = penalty.penalty_type || penalty.penaltyType || {};
    const [statusLabel, statusClass] = penaltyStatus(penalty.status);
    const id = Number(penalty.id);
    const days = penalty.deduction_days ?? type.default_deduction_days ?? 0;
    const close = '';
    const edit = canEditPenalty ? `<button class="btn btn-sm btn-outline-primary penalty-edit-btn" data-bs-toggle="modal" data-bs-target="#editPenaltyModal" data-id="${id}" data-employee-id="${employee.id || ''}" data-type-id="${penalty.penalty_type_id || type.id || ''}" data-date="${window.masarYmd(penalty.penalty_date)}" data-reason="${escapePenaltyHtml(penalty.reason)}" data-details="${escapePenaltyHtml(penalty.action)}" data-amount="${escapePenaltyHtml(penalty.amount)}" data-days="${escapePenaltyHtml(penalty.deduction_days)}" title="تعديل"><i class="bi bi-pencil"></i></button>` : '';
    const remove = canDeletePenalty ? `<form method="POST" action="${penaltyBaseUrl}/${id}" onsubmit="return confirm('هل تريد حذف هذا الإجراء؟')"><input type="hidden" name="_token" value="${escapePenaltyHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>` : '';
    return `<tr data-penalty-id="${id}"><td><a class="employee-link fw-bold" href="${escapePenaltyHtml(employee.id ? `{{ url('/employees') }}/${employee.id}` : '#')}">${escapePenaltyHtml(employee.full_name)}</a></td><td>${escapePenaltyHtml(type.name)}</td><td>${penaltyDate(penalty.penalty_date)}</td><td>${escapePenaltyHtml(penalty.reason)}</td><td>${escapePenaltyHtml(days)}</td><td><span class="badge-soft ${statusClass}">${statusLabel}</span></td><td><div class="d-flex gap-1 flex-wrap">${close}${edit}${remove}</div></td></tr>`;
  };
  const wirePenaltyAjaxForms = (root = document) => {
    root.querySelectorAll('#penaltyModal form, #editPenaltyForm').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الحفظ';
    });
    root.querySelectorAll('form[action*="/discipline/penalties/"][action$="/close"]').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الاعتماد';
    });
    root.querySelectorAll('form[action*="/discipline/penalties/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
      const form = methodInput.form;
      if (!form) return;
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الحذف';
      form.dataset.ajaxConfirm = 'هل تريد حذف هذا الإجراء؟';
      form.removeAttribute('onsubmit');
    });
  };
  wirePenaltyAjaxForms();
  document.querySelectorAll('tbody tr').forEach((row) => {
    const action = row.querySelector('form[action*="/discipline/penalties/"]')?.action;
    const match = action?.match(/\/discipline\/penalties\/(\d+)/);
    if (match) row.dataset.penaltyId = match[1];
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('.penalty-edit-btn');
    if (!button) return;
    const d = button.dataset;
    const form = document.getElementById('editPenaltyForm');
    if (!form) return;
    form.action = `${penaltyBaseUrl}/${d.id}`;
    form.dataset.ajax = 'true';
    document.getElementById('editPenaltyEmployee').value = d.employeeId;
    document.getElementById('editPenaltyType').value = d.typeId;
    document.getElementById('editPenaltyDate').value = d.date;
    document.getElementById('editPenaltyReason').value = d.reason;
    document.getElementById('editPenaltyDetails').value = d.details;
    document.getElementById('editPenaltyAmount').value = d.amount;
    document.getElementById('editPenaltyDays').value = d.days;
  });
  const upsertPenaltyRow = (penalty) => {
    if (!penaltyTableBody || !penalty?.id) return;
    const oldRow = penaltyTableBody.querySelector(`tr[data-penalty-id="${penalty.id}"]`);
    if (oldRow) oldRow.outerHTML = penaltyRow(penalty);
    else { penaltyTableBody.querySelector('td[colspan]')?.closest('tr')?.remove(); penaltyTableBody.insertAdjacentHTML('afterbegin', penaltyRow(penalty)); }
    wirePenaltyAjaxForms(penaltyTableBody);
  };
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.id === 'editPenaltyForm' || form.closest('#penaltyModal')) {
      upsertPenaltyRow(event.detail?.penalty);
      bootstrap.Modal.getInstance(document.getElementById(form.id === 'editPenaltyForm' ? 'editPenaltyModal' : 'penaltyModal'))?.hide();
      if (form.closest('#penaltyModal')) form.reset();
    }
    if (form.querySelector('input[name="_method"][value="DELETE"]')) form.closest('tr')?.remove();
  });

  // Stage 2: approving one penalty updates only its action control.
  document.querySelectorAll('form[action*="/discipline/penalties/"][action$="/close"]').forEach((form) => {
    form.dataset.ajax = 'true';
    form.dataset.ajaxLabel = 'جارٍ الاعتماد';
  });

  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[action*="/discipline/penalties/"][action$="/close"]')) return;
    form.outerHTML = '<span class="badge text-bg-success align-self-center">معتمد</span>';
  });
</script>
@endpush
@endsection
