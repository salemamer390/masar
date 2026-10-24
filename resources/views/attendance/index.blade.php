@extends('layouts.app')
@section('title','الحضور والانصراف')
@section('content')
<div class="page-head"><div><h1>الحضور والانصراف</h1><p>عرض سجلات الحضور والانصراف حسب الفترة والموظف والقسم.</p></div>@if(auth()->user()?->hasPermission('attendance.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceModal"><i class="bi bi-plus-lg"></i> تسجيل حضور</button>@endif</div>
<div class="stat-grid compact attendance-stat-grid">@foreach([['حاضر اليوم',$presentToday,'green','person-check'],['غياب اليوم',$absentToday,'red','person-x'],['متأخرون',$lateToday,'orange','clock-history'],['متوسط الحضور',$avgCheckIn,'blue','clock']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>اليوم</small></div></div>@endforeach</div>
<form class="filter-bar d-flex flex-wrap gap-2" method="GET"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="من تاريخ"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="إلى تاريخ"><select name="employee_id" class="form-select"><option value="">كل الموظفين</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id')==$employee->id)>{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('attendance.index') }}">مسح</a></form>
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>التاريخ</th><th>الموظف</th><th>القسم</th><th>الحضور</th><th>الانصراف</th><th>التأخير</th><th>المصدر</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>@forelse($attendances as $attendance)<tr><td>{{ $attendance->attendance_date?->format('d/m/Y') }}</td><td><a class="employee-link fw-bold" href="{{ route('employees.show', $attendance->employee) }}">{{ $attendance->employee?->full_name }}</a><small class="d-block text-muted">#{{ $attendance->employee?->employee_code }}</small></td><td>{{ $attendance->employee?->department?->name ?? '—' }}</td><td>{{ $attendance->check_in ?? '—' }}</td><td>{{ $attendance->check_out ?? '—' }}</td><td>{{ $attendance->formatted_late }}</td><td>{{ arabic_label($attendance->source ?? 'manual') }}</td><td><span class="badge-soft {{ $attendance->status_badge_class }}">{{ arabic_label($attendance->status) }}</span></td><td>@if(auth()->user()?->hasPermission('attendance.edit'))<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="{{ $attendance->id }}" data-employee-id="{{ $attendance->employee_id }}" data-date="{{ $attendance->attendance_date?->format('Y-m-d') }}" data-check-in="{{ $attendance->check_in }}" data-check-out="{{ $attendance->check_out }}" data-status="{{ $attendance->status }}" data-source="{{ $attendance->source ?? 'manual' }}" title="تعديل"><i class="bi bi-pencil"></i></button><form method="POST" action="{{ route('attendance.destroy', $attendance) }}" onsubmit="return confirm('هل تريد حذف سجل الحضور؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form></div>@endif</td></tr>@empty<tr><td colspan="9" class="text-center py-5">لا توجد سجلات ضمن الفترة المحددة.</td></tr>@endforelse</tbody></table></div></div>
<x-pagination-controls :paginator="$attendances" />
<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('attendance.store') }}">@csrf<div class="modal-header"><h5>تسجيل حضور</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الموظف</label><select name="employee_id" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input class="form-control" type="date" name="attendance_date" value="{{ date('Y-m-d') }}" required></div><div class="col-6"><label class="form-label">الحالة</label><select class="form-select" name="status" required><option value="Present">حاضر</option><option value="Late">متأخر</option><option value="Absent">غائب</option><option value="Leave">إجازة</option></select></div><div class="col-6"><label class="form-label">الحضور <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_in" required></div><div class="col-6"><label class="form-label">الانصراف <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_out" required></div><div class="col-6"><label class="form-label">دقائق التأخير</label><input class="form-control" type="number" min="0" name="late_minutes" value="0"></div><div class="col-6"><label class="form-label">المصدر</label><select class="form-select" name="source"><option value="manual">إدخال يدوي</option><option value="device">جهاز حضور</option><option value="api">تكامل API</option></select></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ كمسودة</button></div></form></div></div></div>
<div class="modal fade" id="editAttendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" id="editAttendanceForm">@csrf @method('PUT')<div class="modal-header"><h5>تعديل سجل الحضور</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الموظف</label><select name="employee_id" id="editAttendanceEmployee" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input name="attendance_date" id="editAttendanceDate" type="date" class="form-control" required></div><div class="col-6"><label class="form-label">الحالة</label><select name="status" id="editAttendanceStatus" class="form-select"><option value="Present">حاضر</option><option value="Late">متأخر</option><option value="Absent">غائب</option><option value="Leave">إجازة</option></select></div><div class="col-6"><label class="form-label">وقت الحضور</label><input name="check_in" id="editAttendanceCheckIn" type="time" class="form-control" required></div><div class="col-6"><label class="form-label">وقت الانصراف</label><input name="check_out" id="editAttendanceCheckOut" type="time" class="form-control" required></div><div class="col-12"><label class="form-label">المصدر</label><select name="source" id="editAttendanceSource" class="form-select"><option value="manual">إدخال يدوي</option><option value="device">جهاز حضور</option><option value="api">تكامل نظامي</option></select></div></div><p class="small text-muted mt-3 mb-0">سيُعاد السجل إلى المسودة للمراجعة بعد حفظ التعديل.</p></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>
@push('styles')<style>.attendance-stat-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.attendance-stat-grid .stat-card{min-height:96px;padding:13px 15px;gap:11px}.attendance-stat-grid .stat-icon{width:42px;height:42px;min-width:42px;border-radius:11px;font-size:18px}.attendance-stat-grid .stat-card b{font-size:21px}.attendance-stat-grid .stat-card small{font-size:10px}@media(max-width:900px){.attendance-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.attendance-stat-grid{grid-template-columns:1fr}.attendance-stat-grid .stat-card{min-height:82px}}</style>@endpush
@push('scripts')<script>document.querySelectorAll('.attendance-edit-btn').forEach(button=>button.addEventListener('click',()=>{const d=button.dataset;const form=document.getElementById('editAttendanceForm');form.action=`{{ url('/attendance') }}/${d.id}`;document.getElementById('editAttendanceEmployee').value=d.employeeId;document.getElementById('editAttendanceDate').value=d.date;document.getElementById('editAttendanceCheckIn').value=d.checkIn;document.getElementById('editAttendanceCheckOut').value=d.checkOut;document.getElementById('editAttendanceStatus').value=d.status;document.getElementById('editAttendanceSource').value=d.source;}));</script>@endpush
@push('scripts')
<script>
  const attendanceBaseUrl = @json(url('/attendance'));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const attendanceTableBody = document.querySelector('.table-panel table tbody');
  const escapeAttendanceHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const attendanceStatus = (status) => ({
    Present: ['حاضر', 'success'], Late: ['متأخر', 'warning'], Absent: ['غائب', 'danger'], Leave: ['إجازة', 'info'],
  }[status] || [status || '—', 'secondary']);
  const attendanceDate = (value) => {
    const parts = String(value || '').slice(0, 10).split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : '—';
  };
  const attendanceLate = (minutes) => `${String(Math.floor(Number(minutes || 0) / 60)).padStart(2, '0')}:${String(Number(minutes || 0) % 60).padStart(2, '0')}`;
  const attendanceRow = (attendance) => {
    const employee = attendance.employee || {};
    const [statusLabel, statusClass] = attendanceStatus(attendance.status);
    const id = Number(attendance.id);
    return `<tr data-attendance-id="${id}">
      <td>${attendanceDate(attendance.attendance_date)}</td>
      <td><a class="employee-link fw-bold" href="${escapeAttendanceHtml(employee.id ? `{{ url('/employees') }}/${employee.id}` : '#')}">${escapeAttendanceHtml(employee.full_name)}</a><small class="d-block text-muted">#${escapeAttendanceHtml(employee.employee_code)}</small></td>
      <td>${escapeAttendanceHtml(employee.department?.name)}</td>
      <td>${escapeAttendanceHtml(attendance.check_in)}</td><td>${escapeAttendanceHtml(attendance.check_out)}</td>
      <td>${attendanceLate(attendance.late_minutes)}</td><td>${escapeAttendanceHtml(attendance.source || 'manual')}</td>
      <td><span class="badge-soft ${statusClass}">${statusLabel}</span></td>
      <td><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="${id}" data-employee-id="${employee.id || ''}" data-date="${String(attendance.attendance_date || '').slice(0, 10)}" data-check-in="${escapeAttendanceHtml(attendance.check_in)}" data-check-out="${escapeAttendanceHtml(attendance.check_out)}" data-status="${escapeAttendanceHtml(attendance.status)}" data-source="${escapeAttendanceHtml(attendance.source || 'manual')}" title="تعديل"><i class="bi bi-pencil"></i></button><form method="POST" action="${attendanceBaseUrl}/${id}" onsubmit="return confirm('هل تريد حذف سجل الحضور؟')"><input type="hidden" name="_token" value="${escapeAttendanceHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form></div></td>
    </tr>`;
  };
  const wireAttendanceAjaxForms = (root = document) => {
    root.querySelectorAll('#attendanceModal form, #editAttendanceForm').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الحفظ';
    });
    root.querySelectorAll('form[action*="/attendance/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
      const form = methodInput.form;
      if (!form) return;
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الحذف';
      form.dataset.ajaxConfirm = 'هل تريد حذف سجل الحضور؟';
      form.removeAttribute('onsubmit');
    });
  };
  wireAttendanceAjaxForms();
  document.querySelectorAll('tbody tr').forEach((row) => {
    const action = row.querySelector('form[action*="/attendance/"]')?.action;
    const match = action?.match(/\/attendance\/(\d+)/);
    if (match) row.dataset.attendanceId = match[1];
  });

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.attendance-edit-btn');
    if (!button) return;
    const d = button.dataset;
    const form = document.getElementById('editAttendanceForm');
    if (!form) return;
    form.action = `${attendanceBaseUrl}/${d.id}`;
    form.dataset.ajax = 'true';
    document.getElementById('editAttendanceEmployee').value = d.employeeId;
    document.getElementById('editAttendanceDate').value = d.date;
    document.getElementById('editAttendanceCheckIn').value = d.checkIn;
    document.getElementById('editAttendanceCheckOut').value = d.checkOut;
    document.getElementById('editAttendanceStatus').value = d.status;
    document.getElementById('editAttendanceSource').value = d.source;
  });

  const upsertAttendanceRow = (attendance) => {
    if (!attendanceTableBody || !attendance?.id) return;
    const oldRow = attendanceTableBody.querySelector(`tr[data-attendance-id="${attendance.id}"]`);
    if (oldRow) oldRow.outerHTML = attendanceRow(attendance);
    else {
      attendanceTableBody.querySelector('td[colspan]')?.closest('tr')?.remove();
      attendanceTableBody.insertAdjacentHTML('afterbegin', attendanceRow(attendance));
    }
    wireAttendanceAjaxForms(attendanceTableBody);
  };
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.id === 'editAttendanceForm' || form.closest('#attendanceModal')) {
      upsertAttendanceRow(event.detail?.attendance);
      bootstrap.Modal.getInstance(document.getElementById(form.id === 'editAttendanceForm' ? 'editAttendanceModal' : 'attendanceModal'))?.hide();
      if (form.closest('#attendanceModal')) form.reset();
    }
    if (form.querySelector('input[name="_method"][value="DELETE"]')) {
      form.closest('tr')?.remove();
    }
  });

</script>
@endpush
@push('scripts')
<script>
(() => {
  const modal = document.getElementById('attendanceModal');
  if (!modal) return;
  const form = modal.querySelector('form');
  const employeeSelect = modal.querySelector('select[name="employee_id"]');
  const checkIn = modal.querySelector('input[name="check_in"]');
  const checkOut = modal.querySelector('input[name="check_out"]');
  const lateMinutes = modal.querySelector('input[name="late_minutes"]');
  if (employeeSelect) {
    const employees = @json($employees->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->full_name, 'code' => $employee->employee_code])->values());
    const byId = new Map(employees.map((employee) => [String(employee.id), employee]));
    const employeeSearch = document.createElement('input');
    employeeSearch.type = 'search'; employeeSearch.className = 'attendance-employee-search';
    employeeSearch.placeholder = 'ابحث باسم الموظف أو الكود';
    employeeSearch.setAttribute('aria-label', 'البحث عن الموظف بالاسم أو الكود');
    const employeeSearchBox = document.createElement('div');
    employeeSearchBox.className = 'search-table attendance-employee-search-box mb-2';
    employeeSearchBox.innerHTML = '<i class="bi bi-search"></i>';
    employeeSearchBox.append(employeeSearch);
    employeeSelect.parentElement?.insertBefore(employeeSearchBox, employeeSelect);
    employeeSelect.classList.remove('form-select');
    employeeSelect.hidden = true;
    employeeSelect.removeAttribute('required');
    const suggestions = document.createElement('div');
    suggestions.className = 'attendance-employee-suggestions';
    employeeSearchBox.after(suggestions);
    [...employeeSelect.options].forEach((option) => {
      const employee = byId.get(String(option.value));
      if (employee) option.textContent = `${employee.name} — #${employee.code}`;
    });
    const renderSuggestions = () => {
      const query = employeeSearch.value.trim().toLocaleLowerCase();
      const matches = employees.filter((employee) => !query || `${employee.name} ${employee.code}`.toLocaleLowerCase().includes(query)).slice(0, 8);
      suggestions.innerHTML = matches.map((employee) => `<button type="button" class="attendance-employee-suggestion" data-employee-id="${employee.id}"><span>${employee.name}</span><small>#${employee.code}</small></button>`).join('');
      suggestions.hidden = matches.length === 0;
      suggestions.querySelectorAll('[data-employee-id]').forEach((option) => option.addEventListener('click', () => {
        const employee = byId.get(String(option.dataset.employeeId));
        if (!employee) return;
        employeeSelect.value = employee.id;
        employeeSearch.value = `${employee.name} — #${employee.code}`;
        suggestions.hidden = true;
      }));
    };
    employeeSearch.addEventListener('input', renderSuggestions);
    employeeSearch.addEventListener('focus', renderSuggestions);
    employeeSelect.addEventListener('change', () => {
      const employee = byId.get(String(employeeSelect.value));
      if (employee) employeeSearch.value = `${employee.name} — #${employee.code}`;
    });
    document.addEventListener('click', (event) => {
      if (!employeeSearchBox.contains(event.target) && !suggestions.contains(event.target)) suggestions.hidden = true;
    });
    employeeSelect.title = 'يمكنك البحث بالاسم أو كود الموظف';
  }
  if (lateMinutes) lateMinutes.closest('.col-6')?.remove();
  const resetDefaults = () => {
    if (checkIn) checkIn.value = @json($defaultShiftStart);
    if (checkOut) checkOut.value = @json($defaultShiftEnd);
  };
  modal.addEventListener('show.bs.modal', resetDefaults);
  modal.querySelector('form')?.addEventListener('reset', () => setTimeout(resetDefaults));
})();
</script>
@endpush
@push('scripts')
<script>
(() => {
  const table = document.querySelector('.table-panel table');
  if (!table) return;
  const records = @json($attendances->getCollection()->mapWithKeys(fn ($attendance) => [$attendance->id => ['day' => (int) $attendance->day_overtime_minutes, 'night' => (int) $attendance->night_overtime_minutes]]));
  const rowOrder = @json($attendances->getCollection()->pluck('id')->values());
  const formatMinutes = (minutes) => `${String(Math.floor(Number(minutes || 0) / 60)).padStart(2, '0')}:${String(Number(minutes || 0) % 60).padStart(2, '0')}`;
  const addOvertimeColumns = (row) => {
    if (!row || row.dataset.overtimeColumns === '1') return;
    const id = row.dataset.attendanceId || row.querySelector('form[action*="/attendance/"]')?.action.match(/\/attendance\/(\d+)/)?.[1] || rowOrder[[...row.parentElement.children].indexOf(row)];
    const overtime = records[id] || {day: 0, night: 0};
    const cells = row.children;
    if (cells.length < 2) return;
    const insertBefore = cells[cells.length - 4];
    const day = document.createElement('td'); day.textContent = formatMinutes(overtime.day); day.dataset.label = 'الإضافي النهاري';
    const night = document.createElement('td'); night.textContent = formatMinutes(overtime.night); night.dataset.label = 'الإضافي الليلي';
    row.insertBefore(day, insertBefore); row.insertBefore(night, insertBefore);
    row.dataset.overtimeColumns = '1';
  };
  const head = table.tHead?.rows[0];
  if (head && !head.dataset.overtimeColumns) {
    const sourceHead = head.cells[6];
    if (sourceHead) { const day = document.createElement('th'); day.textContent = 'الإضافي النهاري'; const night = document.createElement('th'); night.textContent = 'الإضافي الليلي'; head.insertBefore(day, sourceHead); head.insertBefore(night, sourceHead); }
    head.dataset.overtimeColumns = '1';
  }
  table.tBodies[0]?.querySelectorAll('tr').forEach(addOvertimeColumns);
  new MutationObserver(() => table.tBodies[0]?.querySelectorAll('tr').forEach(addOvertimeColumns)).observe(table.tBodies[0], {childList: true});
  document.addEventListener('masar:success', (event) => {
    const attendance = event.detail?.attendance;
    if (!attendance?.id) return;
    records[attendance.id] = {day: Number(attendance.day_overtime_minutes || 0), night: Number(attendance.night_overtime_minutes || 0)};
  });
})();
</script>
@endpush
@push('styles')
<style>
  .attendance-filter-search{min-width:240px;flex:1 1 260px}
  @media(max-width:600px){.attendance-filter-search{min-width:100%;flex-basis:100%}}
</style>
@endpush
@push('scripts')
<script>
(() => {
  const filter = document.querySelector('form.filter-bar');
  if (!filter || filter.querySelector('[name="search"]')) return;
  const wrapper = document.createElement('div');
  wrapper.className = 'search-table attendance-filter-search';
  wrapper.innerHTML = '<i class="bi bi-search"></i><input type="search" name="search" class="form-control" placeholder="ابحث باسم الموظف أو الكود" value="{{ request('search') }}">';
  filter.prepend(wrapper);
  const exportLink = document.createElement('a');
  exportLink.className = 'btn btn-outline-success attendance-export-trigger';
  exportLink.href = '{{ route('attendance.export') }}' + (window.location.search || '');
  exportLink.innerHTML = '<i class="bi bi-file-earmark-spreadsheet"></i> تصدير CSV';
  exportLink.title = 'تصدير النتائج الحالية';
  filter.append(exportLink);
})();
</script>
@endpush
@push('styles')
<style>
</style>
@endpush
@push('scripts')
<script>
/* quick clock controls removed */
/*
  panel.innerHTML = '<select class="form-select" aria-label="اختر الموظف"><option value="">اختر الموظف لتسجيل حركة سريعة</option></select><button type="button" class="btn btn-outline-success" data-clock="in"><i class="bi bi-box-arrow-in-left"></i> تسجيل الحضور الآن</button><button type="button" class="btn btn-outline-primary" data-clock="out"><i class="bi bi-box-arrow-right"></i> تسجيل الانصراف الآن</button>';
  const select = panel.querySelector('select');
  employees.forEach((employee) => { const option = document.createElement('option'); option.value = employee.id; option.textContent = `${employee.name} — #${employee.code}`; select.append(option); });
  filter.before(panel);
  panel.querySelectorAll('[data-clock]').forEach((button) => button.addEventListener('click', async () => {
    if (!select.value) { alert('اختر الموظف أولًا.'); return; }
    button.disabled = true;
    try {
      const response = await fetch('/attendance/clock-disabled', { method: 'POST' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'تعذر تسجيل الحركة.');
      window.location.reload();
    } catch (error) { alert(error.message); } finally { button.disabled = false; }
  }));
*/
</script>
@endpush
@push('styles')
<style>
  .attendance-page-actions{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;margin-inline-start:auto}
  .attendance-page-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:8px 14px;border-radius:10px;white-space:nowrap;font-size:13px}
  @media(max-width:700px){.attendance-page-actions{width:100%;margin-top:10px;justify-content:flex-start;overflow-x:auto}.attendance-page-actions .btn{min-height:36px;padding:7px 10px;font-size:12px;flex:0 0 auto}.page-head{flex-wrap:wrap}}
</style>
@endpush
@push('scripts')
<script>
(() => {
  setTimeout(() => {
    const head = document.querySelector('.page-head');
    if (!head || head.querySelector('.attendance-page-actions')) return;
    const actions = document.createElement('div');
    actions.className = 'attendance-page-actions';
    const title = head.firstElementChild;
    const existing = [...head.children].filter((child) => child !== title);
    existing.forEach((child) => actions.append(child));
    const exportLink = document.querySelector('.attendance-export-trigger');
    if (exportLink) actions.prepend(exportLink);
    if (actions.children.length) head.append(actions);
  }, 0);
})();
</script>
@endpush
@push('scripts')
<script>
(() => {
  document.querySelector('#attendanceModal button.btn-primary')?.replaceChildren(document.createTextNode('حفظ السجل'));
})();
</script>
@endpush
@if(auth()->user()?->hasPermission('attendance.create'))
@push('styles')
<style>
  .attendance-import-trigger{margin-inline-start:auto;white-space:nowrap}
  .attendance-import-modal .modal-content{border:0;border-radius:18px;overflow:hidden}
  .attendance-import-modal .modal-header{background:linear-gradient(135deg,#0d6efd,#0b5ed7);color:#fff}
  .attendance-import-modal .modal-header .btn-close{filter:brightness(0) invert(1)}
  .attendance-import-modal .import-help{background:#f5f8fc;border:1px solid #e1eaf5;border-radius:12px;padding:12px;line-height:1.8}
  @media(max-width:600px){.attendance-import-trigger{width:100%;margin-top:8px}}
</style>
@endpush
@push('scripts')
<script>
(() => {
  const head = document.querySelector('.page-head');
  if (!head || document.getElementById('attendanceImportModal')) return;
  const actions = head.querySelector('.page-head-actions') || head.querySelector('.actions') || head;
  const button = document.createElement('button');
  button.type = 'button'; button.className = 'btn btn-outline-primary attendance-import-trigger';
  button.setAttribute('data-bs-toggle', 'modal'); button.setAttribute('data-bs-target', '#attendanceImportModal');
  button.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> استيراد سجلات';
  actions.append(button);
  document.body.insertAdjacentHTML('beforeend', `
    <div class="modal fade attendance-import-modal" id="attendanceImportModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>استيراد سجلات الحضور</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <form method="POST" action="{{ route('attendance.import') }}" enctype="multipart/form-data">
          @csrf
          <div class="modal-body">
            <div class="import-help mb-3">يجب أن يحتوي الملف على عمود <strong>التاريخ</strong> وعمود <strong>كود الموظف</strong> أو <strong>اسم الموظف</strong>. الأعمدة الاختيارية: الحضور، الانصراف، الحالة. يتم تحديث سجل الموظف داخل شركتك فقط.</div>
            <label class="form-label" for="attendanceImportFile">ملف السجلات</label>
            <input class="form-control" id="attendanceImportFile" type="file" name="file" accept=".xlsx,.csv,.txt" required>
            <div class="form-text">الحد الأقصى 20MB. السجلات المكررة لنفس الموظف والتاريخ يتم تحديثها.</div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> بدء الاستيراد</button></div>
        </form>
      </div></div>
    </div>`);
})();
</script>
@endpush
@endif
@push('styles')
<style>
  .attendance-details-trigger{cursor:pointer;transition:background-color .18s ease,transform .18s ease}
  .attendance-details-trigger:hover{background:#f1f6ff;transform:translateY(-1px)}
  .attendance-details-trigger td:last-child{cursor:default}
  .attendance-detail-list{display:flex;flex-direction:column;gap:8px}
  .attendance-detail-item{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:11px 13px;border:1px solid #e4ebf5;border-radius:10px;background:#f8fafd}
  .attendance-detail-item small{color:#71809a;font-size:12px}
  .attendance-detail-item strong{color:#15294d;font-size:14px;text-align:end}
  .attendance-detail-item--check_out{background:#eef5ff;border-color:#9ec5fe}.attendance-detail-item--check_out strong{color:#1d4ed8}
  .attendance-detail-item--worked{background:#ecfdf5;border-color:#86efac}.attendance-detail-item--worked strong{color:#047857}
  .attendance-detail-item--late{background:#fff8e6;border-color:#f8c76a}.attendance-detail-item--late strong{color:#b45309}
  .attendance-detail-item--early{background:#fff1f2;border-color:#fda4af}.attendance-detail-item--early strong{color:#be123c}
  .attendance-detail-group{margin-bottom:18px}
  .attendance-detail-group-title{display:flex;align-items:center;gap:8px;margin:0 0 9px;padding-bottom:7px;border-bottom:1px solid #e4ebf5;color:#173866;font-size:14px;font-weight:700}
  .attendance-detail-group-title i{color:#2674e8}
  .attendance-detail-group--late .attendance-detail-item strong{color:#b45309}
  .attendance-detail-group--overtime .attendance-detail-item strong{color:#087f5b}
  .attendance-detail-item.is-zero strong{color:#8290a6!important;font-weight:500}
  @media(max-width:576px){.attendance-detail-item{align-items:flex-start;flex-direction:column;gap:3px}.attendance-detail-item strong{text-align:start}}
  @media(max-width:700px){
    .table-panel .table thead{display:none}
    .table-panel .table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px;border:1px solid #e3eaf4;border-radius:14px;margin-bottom:10px;background:#fff}
    .table-panel .table tbody td{display:flex;flex-direction:column;gap:2px;border:0;padding:6px 8px;min-width:0}
    .table-panel .table tbody td::before{font-size:11px;color:#74839c;font-weight:600}
    .table-panel .table tbody td:nth-child(1)::before{content:'التاريخ'} .table-panel .table tbody td:nth-child(2)::before{content:'الموظف'} .table-panel .table tbody td:nth-child(3)::before{content:'القسم'} .table-panel .table tbody td:nth-child(4)::before{content:'الحضور'} .table-panel .table tbody td:nth-child(5)::before{content:'الانصراف'} .table-panel .table tbody td:nth-child(6)::before{content:'التأخير'} .table-panel .table tbody td:nth-child(7)::before{content:'الإضافي النهاري'} .table-panel .table tbody td:nth-child(8)::before{content:'الإضافي الليلي'} .table-panel .table tbody td:nth-child(9)::before{content:'المصدر'} .table-panel .table tbody td:nth-child(10)::before{content:'الحالة'} .table-panel .table tbody td:nth-child(11)::before{content:'الإجراءات'}
    .table-panel .table tbody td:nth-child(2),.table-panel .table tbody td:nth-child(11),.table-panel .table tbody td:nth-child(12){grid-column:1/-1}
    .table-panel .table tbody td[colspan]{display:block;grid-column:1/-1}
    .table-panel .table .btn{width:auto;display:inline-flex}
  }
</style>
@endpush
@push('scripts')
<script>
(() => {
  const table = document.querySelector('.table-panel table');
  if (!table) return;
  @php
    $attendanceDetails = $attendances->getCollection()->mapWithKeys(function ($attendance) {
      return [$attendance->id => [
      'employee' => $attendance->employee?->full_name,
      'code' => $attendance->employee?->employee_code,
      'department' => $attendance->employee?->department?->name,
      'date' => $attendance->attendance_date?->format('d/m/Y'),
      'check_in' => $attendance->check_in,
      'check_out' => $attendance->check_out,
      'late' => $attendance->formatted_late,
      'early' => sprintf('%02d:%02d', intdiv((int) ($attendance->early_leave_minutes ?? 0), 60), ((int) ($attendance->early_leave_minutes ?? 0)) % 60),
      'worked' => $attendance->worked_hours,
      'day_overtime' => sprintf('%02d:%02d', intdiv((int) ($attendance->day_overtime_minutes ?? 0), 60), ((int) ($attendance->day_overtime_minutes ?? 0)) % 60),
      'night_overtime' => sprintf('%02d:%02d', intdiv((int) ($attendance->night_overtime_minutes ?? 0), 60), ((int) ($attendance->night_overtime_minutes ?? 0)) % 60),
      'status' => arabic_label($attendance->status),
      'source' => arabic_label($attendance->source ?? 'manual'),
      ]];
    })->all();
  @endphp
  const details = @json($attendanceDetails);
  const labels = {employee:'الموظف', code:'كود الموظف', department:'القسم', date:'التاريخ', check_in:'الحضور', check_out:'الانصراف', late:'التأخير', early:'الانصراف المبكر', worked:'ساعات العمل', day_overtime:'الإضافي النهاري', night_overtime:'الإضافي الليلي', status:'الحالة', source:'المصدر'};
  const esc = (value) => String(value ?? '—').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  document.body.insertAdjacentHTML('beforeend', `<div class="offcanvas offcanvas-end" tabindex="-1" id="attendanceDetailsPanel" aria-labelledby="attendanceDetailsTitle"><div class="offcanvas-header"><h5 id="attendanceDetailsTitle">تفاصيل سجل الحضور</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button></div><div class="offcanvas-body"><div class="attendance-detail-list" id="attendanceDetailList"></div></div></div>`);
  const panel = document.getElementById('attendanceDetailsPanel');
  const list = document.getElementById('attendanceDetailList');
  table.querySelectorAll('tbody tr[data-attendance-id], tbody tr').forEach((row) => {
    const id = row.dataset.attendanceId || row.querySelector('form[action*="/attendance/"]')?.action.match(/\/attendance\/(\d+)/)?.[1];
    if (!id || !details[id]) return;
    row.dataset.attendanceId = id; row.classList.add('attendance-details-trigger');
    row.addEventListener('click', (event) => {
      if (event.target.closest('a,button,form,input,select,textarea')) return;
      const groups = [
        {title:'أوقات الدوام', icon:'bi-clock', keys:['date','check_in','check_out','worked']},
        {title:'التأخير والانصراف المبكر', icon:'bi-exclamation-triangle', className:'attendance-detail-group--late', keys:['late','early']},
        {title:'الساعات الإضافية', icon:'bi-plus-circle', className:'attendance-detail-group--overtime', keys:['day_overtime','night_overtime']},
      ];
      list.innerHTML = groups.map((group) => `<section class="attendance-detail-group ${group.className || ''}"><h6 class="attendance-detail-group-title"><i class="bi ${group.icon}"></i>${group.title}</h6><div class="attendance-detail-list">${group.keys.filter((key) => Object.prototype.hasOwnProperty.call(details[id], key)).map((key) => { const value = details[id][key]; const zero = ['00:00','0','0.00',0,null,'—'].includes(value); return `<div class="attendance-detail-item attendance-detail-item--${key} ${zero ? 'is-zero' : ''}"><small>${labels[key] || key}</small><strong>${esc(value)}</strong></div>`; }).join('')}</div></section>`).join('');
      bootstrap.Offcanvas.getOrCreateInstance(panel).show();
    });
  });
})();
</script>
@endpush
@push('styles')
<style>
  .attendance-employee-search-box{width:100%;margin-bottom:8px}
  .attendance-employee-search-box input{width:100%;border:0;outline:0;background:transparent}
  .attendance-employee-suggestions{position:relative;z-index:1060;display:flex;flex-direction:column;max-height:220px;overflow-y:auto;margin-top:-6px;margin-bottom:8px;border:1px solid #dbe5f2;border-radius:10px;background:#fff;box-shadow:0 8px 22px rgba(20,45,80,.12)}
  .attendance-employee-suggestions[hidden]{display:none}
  .attendance-employee-suggestion{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:9px 12px;border:0;border-bottom:1px solid #edf1f6;background:#fff;text-align:start;color:#18345d}
  .attendance-employee-suggestion:last-child{border-bottom:0}.attendance-employee-suggestion:hover{background:#eef5ff}.attendance-employee-suggestion small{color:#6b82a3}
</style>
@endpush
@endsection
