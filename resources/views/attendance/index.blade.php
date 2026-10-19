@extends('layouts.app')
@section('title','الحضور والانصراف')
@section('content')
<div class="page-head"><div><h1>الحضور والانصراف</h1><p>عرض سجلات الحضور والانصراف حسب الفترة والموظف والقسم.</p></div>@if(auth()->user()?->hasPermission('attendance.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceModal"><i class="bi bi-plus-lg"></i> تسجيل حضور</button>@endif</div>
<div class="stat-grid compact attendance-stat-grid">@foreach([['حاضر اليوم',$presentToday,'green','person-check'],['غياب اليوم',$absentToday,'red','person-x'],['متأخرون',$lateToday,'orange','clock-history'],['متوسط الحضور',$avgCheckIn,'blue','clock']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>اليوم</small></div></div>@endforeach</div>
<form class="filter-bar d-flex flex-wrap gap-2" method="GET"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="من تاريخ"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="إلى تاريخ"><select name="employee_id" class="form-select"><option value="">كل الموظفين</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id')==$employee->id)>{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('attendance.index') }}">مسح</a></form>
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>التاريخ</th><th>الموظف</th><th>القسم</th><th>الحضور</th><th>الانصراف</th><th>التأخير</th><th>المصدر</th><th>الحالة</th><th>الاعتماد</th><th>الإجراءات</th></tr></thead><tbody>@forelse($attendances as $attendance)<tr><td>{{ $attendance->attendance_date?->format('d/m/Y') }}</td><td><a class="employee-link fw-bold" href="{{ route('employees.show', $attendance->employee) }}">{{ $attendance->employee?->full_name }}</a><small class="d-block text-muted">#{{ $attendance->employee?->employee_code }}</small></td><td>{{ $attendance->employee?->department?->name ?? '—' }}</td><td>{{ $attendance->check_in ?? '—' }}</td><td>{{ $attendance->check_out ?? '—' }}</td><td>{{ $attendance->formatted_late }}</td><td>{{ arabic_label($attendance->source ?? 'manual') }}</td><td><span class="badge-soft {{ $attendance->status_badge_class }}">{{ arabic_label($attendance->status) }}</span></td><td>@if($attendance->approval_status === 'Approved')<span class="badge text-bg-success">معتمد</span>@else<span class="badge text-bg-warning">مسودة</span>@endif @if($attendance->approval_status !== 'Approved' && auth()->user()?->hasPermission('attendance.edit'))<form class="d-inline" method="POST" action="{{ route('attendance.approve', $attendance) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-success ms-1">اعتماد</button></form>@endif</td><td>@if(auth()->user()?->hasPermission('attendance.edit'))<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="{{ $attendance->id }}" data-employee-id="{{ $attendance->employee_id }}" data-date="{{ $attendance->attendance_date?->format('Y-m-d') }}" data-check-in="{{ $attendance->check_in }}" data-check-out="{{ $attendance->check_out }}" data-status="{{ $attendance->status }}" data-source="{{ $attendance->source ?? 'manual' }}" title="تعديل"><i class="bi bi-pencil"></i></button><form method="POST" action="{{ route('attendance.destroy', $attendance) }}" onsubmit="return confirm('هل تريد حذف سجل الحضور؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form></div>@endif</td></tr>@empty<tr><td colspan="10" class="text-center py-5">لا توجد سجلات ضمن الفترة المحددة.</td></tr>@endforelse</tbody></table></div></div>
<x-pagination-controls :paginator="$attendances" />
<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('attendance.store') }}">@csrf<div class="modal-header"><h5>تسجيل حضور</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الموظف</label><select name="employee_id" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input class="form-control" type="date" name="attendance_date" value="{{ date('Y-m-d') }}" required></div><div class="col-6"><label class="form-label">الحالة</label><select class="form-select" name="status" required><option value="Present">حاضر</option><option value="Late">متأخر</option><option value="Absent">غائب</option><option value="Leave">إجازة</option></select></div><div class="col-6"><label class="form-label">الحضور <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_in" required></div><div class="col-6"><label class="form-label">الانصراف <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_out" required></div><div class="col-6"><label class="form-label">دقائق التأخير</label><input class="form-control" type="number" min="0" name="late_minutes" value="0"></div><div class="col-6"><label class="form-label">المصدر</label><select class="form-select" name="source"><option value="manual">إدخال يدوي</option><option value="device">جهاز حضور</option><option value="api">تكامل API</option></select></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ كمسودة</button></div></form></div></div></div>
<div class="modal fade" id="editAttendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" id="editAttendanceForm">@csrf @method('PUT')<div class="modal-header"><h5>تعديل سجل الحضور</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الموظف</label><select name="employee_id" id="editAttendanceEmployee" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input name="attendance_date" id="editAttendanceDate" type="date" class="form-control" required></div><div class="col-6"><label class="form-label">الحالة</label><select name="status" id="editAttendanceStatus" class="form-select"><option value="Present">حاضر</option><option value="Late">متأخر</option><option value="Absent">غائب</option><option value="Leave">إجازة</option></select></div><div class="col-6"><label class="form-label">وقت الحضور</label><input name="check_in" id="editAttendanceCheckIn" type="time" class="form-control" required></div><div class="col-6"><label class="form-label">وقت الانصراف</label><input name="check_out" id="editAttendanceCheckOut" type="time" class="form-control" required></div><div class="col-12"><label class="form-label">المصدر</label><select name="source" id="editAttendanceSource" class="form-select"><option value="manual">إدخال يدوي</option><option value="device">جهاز حضور</option><option value="api">تكامل نظامي</option></select></div></div><p class="small text-muted mt-3 mb-0">سيُعاد السجل إلى المسودة للمراجعة بعد حفظ التعديل.</p></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>
@push('styles')<style>.attendance-stat-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.attendance-stat-grid .stat-card{min-height:96px;padding:13px 15px;gap:11px}.attendance-stat-grid .stat-icon{width:42px;height:42px;min-width:42px;border-radius:11px;font-size:18px}.attendance-stat-grid .stat-card b{font-size:21px}.attendance-stat-grid .stat-card small{font-size:10px}@media(max-width:900px){.attendance-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.attendance-stat-grid{grid-template-columns:1fr}.attendance-stat-grid .stat-card{min-height:82px}}</style>@endpush
@push('scripts')<script>document.querySelectorAll('.attendance-edit-btn').forEach(button=>button.addEventListener('click',()=>{const d=button.dataset;const form=document.getElementById('editAttendanceForm');form.action=`{{ url('/attendance') }}/${d.id}`;document.getElementById('editAttendanceEmployee').value=d.employeeId;document.getElementById('editAttendanceDate').value=d.date;document.getElementById('editAttendanceCheckIn').value=d.checkIn;document.getElementById('editAttendanceCheckOut').value=d.checkOut;document.getElementById('editAttendanceStatus').value=d.status;document.getElementById('editAttendanceSource').value=d.source;}));</script>@endpush
@push('scripts')
<script>
  const attendanceBaseUrl = @json(url('/attendance'));
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
    const approved = attendance.approval_status === 'Approved';
    return `<tr data-attendance-id="${id}">
      <td>${attendanceDate(attendance.attendance_date)}</td>
      <td><a class="employee-link fw-bold" href="${escapeAttendanceHtml(employee.id ? `{{ url('/employees') }}/${employee.id}` : '#')}">${escapeAttendanceHtml(employee.full_name)}</a><small class="d-block text-muted">#${escapeAttendanceHtml(employee.employee_code)}</small></td>
      <td>${escapeAttendanceHtml(employee.department?.name)}</td>
      <td>${escapeAttendanceHtml(attendance.check_in)}</td><td>${escapeAttendanceHtml(attendance.check_out)}</td>
      <td>${attendanceLate(attendance.late_minutes)}</td><td>${escapeAttendanceHtml(attendance.source || 'manual')}</td>
      <td><span class="badge-soft ${statusClass}">${statusLabel}</span></td>
      <td>${approved ? '<span class="badge text-bg-success">معتمد</span>' : `<span class="badge text-bg-warning">مسودة</span> <form class="d-inline" method="POST" action="${attendanceBaseUrl}/${id}/approve"><input type="hidden" name="_token" value="${escapeAttendanceHtml(csrfToken)}"><input type="hidden" name="_method" value="PATCH"><button class="btn btn-sm btn-outline-success ms-1">اعتماد</button></form>`}</td>
      <td><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="${id}" data-employee-id="${employee.id || ''}" data-date="${String(attendance.attendance_date || '').slice(0, 10)}" data-check-in="${escapeAttendanceHtml(attendance.check_in)}" data-check-out="${escapeAttendanceHtml(attendance.check_out)}" data-status="${escapeAttendanceHtml(attendance.status)}" data-source="${escapeAttendanceHtml(attendance.source || 'manual')}" title="تعديل"><i class="bi bi-pencil"></i></button><form method="POST" action="${attendanceBaseUrl}/${id}" onsubmit="return confirm('هل تريد حذف سجل الحضور؟')"><input type="hidden" name="_token" value="${escapeAttendanceHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form></div></td>
    </tr>`;
  };
  const wireAttendanceAjaxForms = (root = document) => {
    root.querySelectorAll('#attendanceModal form, #editAttendanceForm').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الحفظ';
    });
    root.querySelectorAll('form[action*="/attendance/"][action$="/approve"]').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = 'جارٍ الاعتماد';
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

  // Stage 2: approving a single attendance record is a safe, row-level update.
  // Other attendance forms still use their existing server-rendered flow.
  document.querySelectorAll('form[action*="/attendance/"][action$="/approve"]').forEach((form) => {
    form.dataset.ajax = 'true';
    form.dataset.ajaxLabel = 'جارٍ الاعتماد';
  });

  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[action*="/attendance/"][action$="/approve"]')) return;
    const cell = form.closest('td');
    if (!cell) return;
    cell.innerHTML = '<span class="badge text-bg-success">معتمد</span>';
  });
</script>
@endpush
@endsection
