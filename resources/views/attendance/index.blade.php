@extends('layouts.app')
@section('title',__('الحضور والانصراف'))
@section('content')
@if(session('attendance_import_errors'))
<div class="alert alert-warning mt-3"><strong>{{ __('مراجعة استيراد الحضور:') }}</strong><ul class="mb-0 mt-2">@foreach(session('attendance_import_errors') as $importError)<li>{{ $importError }}</li>@endforeach</ul></div>
@endif
<div class="attendance-today-label"><i class="bi bi-calendar-day"></i> {{ __('اليوم —') }} {{ today()->locale(app()->getLocale())->translatedFormat('l d/m/Y') }}</div>
<div class="attendance-stat-grid">@foreach([[__('حاضر اليوم'),$presentToday,'green','person-check'],[__('غياب اليوم'),$absentToday,'red','person-x'],[__('متأخرون'),$lateToday,'orange','clock-history'],[__('متوسط الحضور'),$avgCheckIn,'blue','clock']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>{{ __('اليوم') }}</small></div></div>@endforeach</div>
<div class="attendance-toolbar">
<nav class="attendance-tabs" aria-label="{{ __('أقسام الحضور') }}"><a class="attendance-tab active" href="{{ route('attendance.index') }}"><i class="bi bi-calendar-check"></i> {{ __('الفترة الحالية') }}</a><a class="attendance-tab" href="{{ route('attendance.archive.index') }}"><i class="bi bi-archive"></i> {{ __('الشهور السابقة') }}</a><a class="attendance-tab" href="{{ route('attendance.exceptions.index') }}">{{ __('استثناءات البصمات') }} <span class="badge bg-warning text-dark">{{ \App\Models\AttendanceImportDay::where('resolution','pending')->count() }}</span></a></nav>

</div>
@if(\App\Models\AttendanceImportDay::where('resolution','pending')->exists())
<div class="alert alert-warning">{{ __('توجد بصمات تحتاج مراجعة قبل قفل الفترة.') }} <a href="{{ route('attendance.exceptions.index') }}">{{ __('فتح الاستثناءات') }}</a></div>
@endif
@foreach($pendingPeriods as $pending)<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-hourglass-split"></i> {{ __('الفترة السابقة') }} {{ $pending->period_start->format('d/m/Y') }} - {{ $pending->period_end->format('d/m/Y') }} {{ __('ما زالت متاحة للتصحيح حتى') }} {{ $pending->closes_on->copy()->subDay()->format('d/m/Y') }}{{ __('، وبعدها تتقفل وتتحفظ في «الشهور السابقة».') }} <a href="{{ route('attendance.index', ['from_date' => $pending->period_start->toDateString(), 'to_date' => $pending->period_end->toDateString()]) }}">{{ __('عرض حضور الفترة') }}</a></div>@endforeach
<p class="text-muted small mb-2">{{ __('الفترة الحالية: من') }} {{ \Carbon\Carbon::parse($periodStart)->format('d/m/Y') }} {{ __('إلى') }} {{ \Carbon\Carbon::parse($periodEnd)->format('d/m/Y') }}</p>
@if($errors->any())
<div class="alert alert-danger py-2 px-3 small mb-2">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif
@php
$advancedFilters = request()->filled('from_date') || request()->filled('to_date') || request()->filled('employee_id') || request()->filled('department_id');
@endphp
<form class="filter-bar attendance-filters" method="GET" data-server-live-search="true">
<div class="attendance-filter-main">
<div class="attendance-filter-controls">
<div class="search-table attendance-filter-search"><i class="bi bi-search"></i><input type="search" name="search" class="form-control" placeholder="{{ __('ابحث باسم الموظف أو الكود') }}" value="{{ request('search') }}"></div>
<select name="state" class="form-select" title="{{ __('الحالة') }}"><option value="">{{ __('كل الحالات') }}</option><option value="present" @selected(request('state')==='present')>{{ __('حاضر') }}</option><option value="late" @selected(request('state')==='late')>{{ __('متأخر') }}</option><option value="absent" @selected(request('state')==='absent')>{{ __('غائب') }}</option></select>

<button type="button" class="attendance-more-filters{{ $advancedFilters ? ' active' : '' }}" data-target="attendanceFilterExtra" aria-expanded="{{ $advancedFilters ? 'true' : 'false' }}"><i class="bi bi-sliders"></i> {{ __('فلاتر متقدمة') }}</button>
</div>
<div class="attendance-filter-actions">@if(auth()->user()?->hasPermission('attendance.create'))<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceModal"><i class="bi bi-plus-lg"></i> {{ __('تسجيل حضور') }}</button>@endif<a class="btn btn-success" href="{{ route('attendance.export') }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}" title="{{ __('تصدير النتائج الحالية') }}"><i class="bi bi-file-earmark-spreadsheet"></i> {{ __('تصدير CSV') }}</a>@if(auth()->user()?->hasPermission('attendance.create'))<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceImportModal"><i class="bi bi-file-earmark-arrow-up"></i> {{ __('استيراد') }}</button>@endif</div>
</div>
<div class="attendance-filter-extra" id="attendanceFilterExtra"{{ $advancedFilters ? '' : ' hidden' }}>
<input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="{{ __('من تاريخ') }}"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="{{ __('إلى تاريخ') }}"><select name="employee_id" class="form-select"><option value="">{{ __('كل الموظفين') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id')==$employee->id)>{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><select name="department_id" class="form-select"><option value="">{{ __('كل الأقسام') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id')==$department->id)>{{ $department->name }}</option>@endforeach</select>
</div>
</form>
<div class="attendance-period-totals">
<span class="totals-title">{{ __('إجمالي الفترة حتى') }} {{ $classifyTo->format('d/m/Y') }}:</span>
<span><i class="bi bi-person-check text-success"></i> {{ __('حضور') }} <b>{{ $periodTotals['present'] }}</b> {{ __('يوم') }}</span>
<span><i class="bi bi-clock-history" style="color:#b9780b"></i> {{ __('تأخير') }} <b>{{ $periodTotals['late'] }}</b> {{ __('يوم') }}</span>
<span><i class="bi bi-person-x text-danger"></i> {{ __('غياب') }} <b>{{ $periodTotals['absent'] }}</b> {{ __('يوم') }}</span>
</div>
<div class="attendance-legend small text-muted">
<span><span class="legend-swatch legend-late"></span> {{ __('سجل به تأخير') }}</span>
<span><span class="legend-swatch legend-absent"></span> {{ __('سجل غياب مسجَّل') }}</span>
<span><span class="legend-swatch legend-rejected"></span> {{ __('سجل مرفوض (لا يُحتسب)') }}</span>
</div>
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('الموظف') }}</th><th>{{ __('القسم') }}</th><th>{{ __('الحضور') }}</th><th>{{ __('الانصراف') }}</th><th>{{ __('التأخير') }}</th><th>{{ __('المصدر') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('الإجراءات') }}</th></tr></thead><tbody>
@forelse($attendances as $row)
<tr class="{{ $row['row_class'] }}" data-attendance-id="{{ $row['model']->id }}"><td data-label="التاريخ">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td><td data-label="الموظف"><a class="employee-link fw-bold" href="{{ route('employees.show', $row['employee']) }}">{{ $row['employee']?->full_name }}</a><small class="d-block text-muted">#{{ $row['employee']?->employee_code }}</small></td><td data-label="القسم">{{ $row['employee']?->department?->name ?? '—' }}</td><td data-label="الحضور">{{ $row['model']?->check_in ?? '—' }}</td><td data-label="الانصراف">{{ $row['model']?->check_out ?? '—' }}</td><td data-label="التأخير">{{ $row['model'] ? $row['model']->formatted_late : '—' }}</td><td data-label="المصدر">{{ $row['model'] ? arabic_label($row['model']->source ?? 'manual') : '—' }}</td><td data-label="الحالة"><span class="badge-soft {{ $row['badge_class'] }}">{{ $row['status_label'] }}</span></td><td data-label="الإجراءات">
@if(auth()->user()?->hasPermission('attendance.edit') || auth()->user()?->hasPermission('attendance.delete'))
<div class="attendance-row-actions">@if(auth()->user()?->hasPermission('attendance.edit'))<button class="attendance-row-btn attendance-row-edit attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="{{ $row['model']->id }}" data-employee-id="{{ $row['model']->employee_id }}" data-date="{{ $row['model']->attendance_date?->format('Y-m-d') }}" data-check-in="{{ $row['model']->check_in }}" data-check-out="{{ $row['model']->check_out }}" data-status="{{ $row['model']->status }}" data-source="{{ $row['model']->source ?? 'manual' }}" title="{{ __('تعديل') }}"><i class="bi bi-pencil"></i></button>@endif @if(auth()->user()?->hasPermission('attendance.delete'))<form method="POST" action="{{ route('attendance.destroy', $row['model']) }}" onsubmit="return confirm('هل تريد حذف سجل الحضور؟')">@csrf @method('DELETE')<button class="attendance-row-btn attendance-row-delete" title="{{ __('حذف') }}"><i class="bi bi-trash"></i></button></form>@endif</div>
@endif
</td></tr>
@empty
<tr><td colspan="9" class="text-center py-5">{{ __('لا توجد سجلات ضمن الفترة المحددة.') }}</td></tr>
@endforelse
</tbody></table></div></div>
<x-pagination-controls :paginator="$attendances" />
@php
$recordModels = $attendances->getCollection()->pluck('model')->filter()->values();
@endphp
<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('attendance.store') }}">@csrf<div class="modal-header"><h5>{{ __('تسجيل حضور') }}</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">{{ __('التاريخ') }}</label><input class="form-control" type="date" name="attendance_date" value="{{ date('Y-m-d') }}" required></div><div class="col-6"><label class="form-label">{{ __('الحضور') }} <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_in" required></div><div class="col-6"><label class="form-label">{{ __('الانصراف') }} <span class="text-danger">*</span></label><input class="form-control" type="time" name="check_out" required></div><div class="col-6"><label class="form-label">{{ __('دقائق التأخير') }}</label><input class="form-control" type="number" min="0" name="late_minutes" value="0"></div><div class="col-6"><label class="form-label">{{ __('المصدر') }}</label><select class="form-select" name="source"><option value="manual">{{ __('إدخال يدوي') }}</option><option value="device">{{ __('جهاز حضور') }}</option><option value="api">{{ __('تكامل API') }}</option></select></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ كمسودة') }}</button></div></form></div></div></div>
<div class="modal fade" id="editAttendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" id="editAttendanceForm">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('تعديل سجل الحضور') }}</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" id="editAttendanceEmployee" class="form-select" required>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select></div><div class="row g-2"><div class="col-6"><label class="form-label">{{ __('التاريخ') }}</label><input name="attendance_date" id="editAttendanceDate" type="date" class="form-control" required></div><div class="col-6"><label class="form-label">{{ __('وقت الحضور') }}</label><input name="check_in" id="editAttendanceCheckIn" type="time" class="form-control" required></div><div class="col-6"><label class="form-label">{{ __('وقت الانصراف') }}</label><input name="check_out" id="editAttendanceCheckOut" type="time" class="form-control" required></div><div class="col-12"><label class="form-label">{{ __('المصدر') }}</label><select name="source" id="editAttendanceSource" class="form-select"><option value="manual">{{ __('إدخال يدوي') }}</option><option value="device">{{ __('جهاز حضور') }}</option><option value="api">{{ __('تكامل نظامي') }}</option></select></div></div><p class="small text-muted mt-3 mb-0">{{ __('سيُعاد السجل إلى المسودة للمراجعة بعد حفظ التعديل.') }}</p></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ التعديل') }}</button></div></form></div></div></div>
@push('styles')<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-01.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-01.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-02.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-02.css')) }}">@endpush
@push('scripts')
<script>
(() => {
  const attendanceFiltersForm = document.querySelector('form.attendance-filters');
  if (!attendanceFiltersForm) return;
  let attendanceSearchTimer = null;
  const submitAttendanceFilters = () => {
    clearTimeout(attendanceSearchTimer);
    attendanceFiltersForm.requestSubmit();
  };
  attendanceFiltersForm.querySelectorAll('input[type="date"]').forEach((dateInput) => {
    dateInput.addEventListener('change', submitAttendanceFilters);
  });
  const attendanceSearchInput = attendanceFiltersForm.querySelector('input[name="search"]');
  if (attendanceSearchInput) {
    attendanceSearchInput.addEventListener('input', () => {
      clearTimeout(attendanceSearchTimer);
      attendanceSearchTimer = setTimeout(submitAttendanceFilters, 600);
    });
    attendanceSearchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitAttendanceFilters();
      }
    });
  }
})();
</script>
@endpush
@push('scripts')<script>document.querySelectorAll('.attendance-edit-btn').forEach(button=>button.addEventListener('click',()=>{const d=button.dataset;const form=document.getElementById('editAttendanceForm');const time=(value)=>String(value||'').slice(0,5);form.action=`{{ url('/attendance') }}/${d.id}`;document.getElementById('editAttendanceEmployee').value=d.employeeId;document.getElementById('editAttendanceDate').value=d.date;document.getElementById('editAttendanceCheckIn').value=time(d.checkIn);document.getElementById('editAttendanceCheckOut').value=time(d.checkOut);document.getElementById('editAttendanceSource').value=d.source;}));</script>@endpush
@push('scripts')
<script>
(() => {
  const attendanceBaseUrl = @json(url('/attendance'));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const attendanceTableBody = document.querySelector('.table-panel table tbody');
  const escapeAttendanceHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const attendanceStatus = (status) => ({
    Present: [window.masarT('حاضر'), 'success'], Late: [window.masarT('متأخر'), 'warning'], Absent: [window.masarT('غائب'), 'danger'], Leave: [window.masarT('إجازة'), 'info'],
  }[status] || [status || '—', 'secondary']);
  const attendanceDate = (value) => {
    const parts = window.masarYmd(value).split('-');
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
      <td><div class="attendance-row-actions"><button class="attendance-row-btn attendance-row-edit attendance-edit-btn" data-bs-toggle="modal" data-bs-target="#editAttendanceModal" data-id="${id}" data-employee-id="${employee.id || ''}" data-date="${window.masarYmd(attendance.attendance_date)}" data-check-in="${escapeAttendanceHtml(attendance.check_in)}" data-check-out="${escapeAttendanceHtml(attendance.check_out)}" data-status="${escapeAttendanceHtml(attendance.status)}" data-source="${escapeAttendanceHtml(attendance.source || 'manual')}" title="${window.masarT("تعديل")}"><i class="bi bi-pencil"></i></button><form method="POST" action="${attendanceBaseUrl}/${id}" onsubmit="return confirm('${window.masarT("هل تريد حذف سجل الحضور؟")}')"><input type="hidden" name="_token" value="${escapeAttendanceHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button class="attendance-row-btn attendance-row-delete" title="${window.masarT("حذف")}"><i class="bi bi-trash"></i></button></form></div></td>
    </tr>`;
  };
  const wireAttendanceAjaxForms = (root = document) => {
    root.querySelectorAll('#attendanceModal form, #editAttendanceForm').forEach((form) => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = window.masarT('جارٍ الحفظ');
    });
    root.querySelectorAll('form[action*="/attendance/"] input[name="_method"][value="DELETE"]').forEach((methodInput) => {
      const form = methodInput.form;
      if (!form) return;
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = window.masarT('جارٍ الحذف');
      form.dataset.ajaxConfirm = window.masarT('هل تريد حذف سجل الحضور؟');
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
    document.getElementById('editAttendanceCheckIn').value = String(d.checkIn || '').slice(0, 5);
    document.getElementById('editAttendanceCheckOut').value = String(d.checkOut || '').slice(0, 5);
    document.getElementById('editAttendanceSource').value = d.source;
  });

  document.querySelectorAll('.attendance-more-filters').forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const extra = document.getElementById(toggle.dataset.target);
      if (!extra) return;
      const show = extra.hasAttribute('hidden');
      extra.toggleAttribute('hidden', !show);
      toggle.classList.toggle('active', show);
      toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
    });
  });

  const upsertAttendanceRow = (attendance) => {
    if (!attendanceTableBody || !attendance?.id) return;
    const savedEmployeeId = attendance.employee?.id ?? attendance.employee_id;
    const savedDate = window.masarYmd(attendance.attendance_date);
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

})();
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
    employeeSearch.placeholder = window.masarT('ابحث باسم الموظف أو الكود');
    employeeSearch.setAttribute('aria-label', window.masarT('البحث عن الموظف بالاسم أو الكود'));
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
    employeeSelect.title = window.masarT('يمكنك البحث بالاسم أو كود الموظف');
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
  const records = @json($recordModels->mapWithKeys(fn ($attendance) => [$attendance->id => ['day' => (int) $attendance->day_overtime_minutes, 'night' => (int) $attendance->night_overtime_minutes]]));
  const rowOrder = @json($recordModels->pluck('id')->values());
  const formatMinutes = (minutes) => `${String(Math.floor(Number(minutes || 0) / 60)).padStart(2, '0')}:${String(Number(minutes || 0) % 60).padStart(2, '0')}`;
  const addOvertimeColumns = (row) => {
    if (!row || row.dataset.overtimeColumns === '1') return;
    const id = row.dataset.attendanceId || row.querySelector('form[action*="/attendance/"]')?.action.match(/\/attendance\/(\d+)/)?.[1] || rowOrder[[...row.parentElement.children].indexOf(row)];
    const overtime = (id && records[id]) || null;
    const cells = row.children;
    if (cells.length < 2) return;
    // Keep the dynamically added cells aligned with the static header order:
    // late, day overtime, night overtime, source, status, actions.
    const insertBefore = cells[cells.length - 3];
    const day = document.createElement('td'); day.textContent = overtime ? formatMinutes(overtime.day) : '—'; day.dataset.label = @js(__('الإضافي النهاري'));
    const night = document.createElement('td'); night.textContent = overtime ? formatMinutes(overtime.night) : '—'; night.dataset.label = @js(__('الإضافي الليلي'));
    row.insertBefore(day, insertBefore); row.insertBefore(night, insertBefore);
    row.dataset.overtimeColumns = '1';
  };
  const head = table.tHead?.rows[0];
  if (head && !head.dataset.overtimeColumns) {
    const sourceHead = head.cells[6];
    if (sourceHead) { const day = document.createElement('th'); day.textContent = @js(__('الإضافي النهاري')); const night = document.createElement('th'); night.textContent = @js(__('الإضافي الليلي')); head.insertBefore(day, sourceHead); head.insertBefore(night, sourceHead); }
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
<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-03.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-03.css')) }}">
@endpush
@push('styles')

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
<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-04.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-04.css')) }}">
@endpush
@push('scripts')
<script>
(() => {
  document.querySelector('#attendanceModal button.btn-primary')?.replaceChildren(document.createTextNode(window.masarT('حفظ السجل')));
})();
</script>
@endpush
@if(auth()->user()?->hasPermission('attendance.create'))
@push('styles')
<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-05.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-05.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('js/import-dropzone.js') }}?v={{ filemtime(public_path('js/import-dropzone.js')) }}"></script>
<script>
(() => {
  if (document.getElementById('attendanceImportModal')) return;
  document.body.insertAdjacentHTML('beforeend', `
    <div class="modal fade attendance-import-modal" id="attendanceImportModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>استيراد سجلات الحضور</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <form method="POST" action="{{ route('attendance.import.preview') }}" enctype="multipart/form-data">
          @csrf
          <div class="modal-body">
            <div class="import-help mb-3">يدعم الملف ثلاثة أشكال: سجلات يومية (صف لكل موظف ويوم)، أو بصمات خام (صف لكل بصمة)، أو ورقة لكل موظف. المطابقة بكود الموظف — البصمة الأولى للحضور والثانية للانصراف، والاسم للمراجعة فقط. تُعرض معاينة قبل الحفظ لتأكيد الورقة وتعيين الأعمدة. لا يُحفظ أي شيء قبل اعتمادك.</div>
            <label class="form-label" for="attendanceImportFile">ملف السجلات</label>
            <input class="form-control" id="attendanceImportFile" type="file" name="file" accept=".xlsx,.csv,.txt" required>
            <div class="form-text">الحد الأقصى 20MB. السجلات المكررة لنفس الموظف والتاريخ يتم تحديثها.</div>
          </div>
          <div class="px-3 pb-2" data-att-progress hidden role="status" aria-live="polite"><div class="small mb-1" data-att-progress-label>{{ __('جارٍ رفع الملف...') }}</div><progress class="w-100" max="100" data-att-progress-bar aria-label="{{ __('تقدم رفع الملف') }}"></progress></div>
          <div class="alert alert-danger small mx-3" data-att-error hidden role="alert"></div>
          <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> بدء الاستيراد</button></div>
        </form>
      </div></div>
    </div>`);
  const modal = document.getElementById('attendanceImportModal');
  const form = modal.querySelector('form');
  const input = modal.querySelector('#attendanceImportFile');
  window.MasarImportDropzone?.init(input, { maxBytes: 20 * 1024 * 1024, i18n: { title: @js(__('اسحب الملف وأفلته هنا، أو اضغط للاختيار')), badType: @js(__('نوع الملف غير مدعوم. المسموح: ')), tooBig: @js(__('الملف أكبر من الحجم المسموح (')), oneFile: @js(__('ارفع ملفًا واحدًا فقط.')) } });
  const panel = modal.querySelector('[data-att-progress]');
  const bar = modal.querySelector('[data-att-progress-bar]');
  const label = modal.querySelector('[data-att-progress-label]');
  const errBox = modal.querySelector('[data-att-error]');
  const submitBtn = form.querySelector('button[type=submit]');
  const showErr = (m) => { errBox.hidden = !m; errBox.textContent = m || ''; };
  form.addEventListener('submit', (e) => {
    if (!window.XMLHttpRequest || !window.FormData) return;
    e.preventDefault();
    showErr('');
    if (!input.files.length) { showErr(@js(__('اختر ملفًا أولًا.'))); return; }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Import-Upload', '1');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    panel.hidden = false; submitBtn.disabled = true;
    bar.removeAttribute('value'); label.textContent = @js(__('جارٍ رفع الملف...'));
    xhr.upload.onprogress = (ev) => {
      if (!ev.lengthComputable) return;
      const p = Math.min(100, Math.floor(ev.loaded / ev.total * 100));
      bar.value = p; label.textContent = @js(__('رفع الملف: ')) + p + @js(__('٪'));
    };
    xhr.upload.onload = () => { bar.removeAttribute('value'); label.textContent = @js(__('اكتمل الرفع. جارٍ فحص الملف على الخادم...')); };
    const done = () => { panel.hidden = true; submitBtn.disabled = false; };
    xhr.onerror = xhr.onabort = xhr.ontimeout = () => { done(); showErr(@js(__('تعذر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.'))); };
    xhr.onload = () => {
      done();
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const d = JSON.parse(xhr.responseText);
          if (d.import_token) {
            const f = document.createElement('form');
            f.method = 'POST'; f.action = form.action;
            [['_token', form.querySelector('input[name=_token]').value], ['import_token', d.import_token]].forEach(([n, v]) => {
              const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i);
            });
            document.body.appendChild(f); f.submit();
            return;
          }
        } catch (x) {}
      }
      let msg = '';
      try { const d = JSON.parse(xhr.responseText); msg = d.errors ? Object.values(d.errors).flat()[0] : d.message; } catch (x) {}
      if (xhr.status === 419) msg = @js(__('انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مرة أخرى.'));
      if (xhr.status === 413) msg = @js(__('الملف أكبر من الحجم المسموح به على الخادم.'));
      showErr(msg || @js(__('حدث خطأ غير متوقع أثناء معالجة الملف.')));
    };
    xhr.send(new FormData(form));
  });
})();
</script>
@endpush
@endif
@push('styles')
<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-06.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-06.css')) }}">
@endpush
@push('scripts')
<script>
(() => {
  const table = document.querySelector('.table-panel table');
  if (!table) return;
  @php
    $attendanceDetails = $recordModels->mapWithKeys(function ($attendance) {
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
  const labels = {employee:window.masarT('الموظف'), code:window.masarT('كود الموظف'), department:window.masarT('القسم'), date:window.masarT('التاريخ'), check_in:window.masarT('الحضور'), check_out:window.masarT('الانصراف'), late:window.masarT('التأخير'), early:window.masarT('الانصراف المبكر'), worked:window.masarT('ساعات العمل'), day_overtime:window.masarT('الإضافي النهاري'), night_overtime:window.masarT('الإضافي الليلي'), status:window.masarT('الحالة'), source:window.masarT('المصدر')};
  const esc = (value) => String(value ?? '—').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  document.body.insertAdjacentHTML('beforeend', `<div class="offcanvas offcanvas-end" tabindex="-1" id="attendanceDetailsPanel" aria-labelledby="attendanceDetailsTitle"><div class="offcanvas-header"><h5 id="attendanceDetailsTitle">${window.masarT("تفاصيل سجل الحضور")}</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="${window.masarT("إغلاق")}"></button></div><div class="offcanvas-body"><div class="attendance-detail-list" id="attendanceDetailList"></div></div></div>`);
  const panel = document.getElementById('attendanceDetailsPanel');
  const list = document.getElementById('attendanceDetailList');
  table.querySelectorAll('tbody tr[data-attendance-id], tbody tr').forEach((row) => {
    const id = row.dataset.attendanceId || row.querySelector('form[action*="/attendance/"]')?.action.match(/\/attendance\/(\d+)/)?.[1];
    if (!id || !details[id]) return;
    row.dataset.attendanceId = id; row.classList.add('attendance-details-trigger');
    row.addEventListener('click', (event) => {
      if (event.target.closest('a,button,form,input,select,textarea')) return;
      const groups = [
        {title:window.masarT('أوقات الدوام'), icon:'bi-clock', keys:['date','check_in','check_out','worked']},
        {title:window.masarT('التأخير والانصراف المبكر'), icon:'bi-exclamation-triangle', className:'attendance-detail-group--late', keys:['late','early']},
        {title:window.masarT('الساعات الإضافية'), icon:'bi-plus-circle', className:'attendance-detail-group--overtime', keys:['day_overtime','night_overtime']},
      ];
      list.innerHTML = groups.map((group) => `<section class="attendance-detail-group ${group.className || ''}"><h6 class="attendance-detail-group-title"><i class="bi ${group.icon}"></i>${group.title}</h6><div class="attendance-detail-list">${group.keys.filter((key) => Object.prototype.hasOwnProperty.call(details[id], key)).map((key) => { const value = details[id][key]; const zero = ['00:00','0','0.00',0,null,'—'].includes(value); return `<div class="attendance-detail-item attendance-detail-item--${key} ${zero ? 'is-zero' : ''}"><small>${labels[key] || key}</small><strong>${esc(value)}</strong></div>`; }).join('')}</div></section>`).join('');
      bootstrap.Offcanvas.getOrCreateInstance(panel).show();
    });
  });
})();
</script>
@endpush
@push('styles')
<link rel="stylesheet" href="{{ asset('css/pages/attendance-index-07.css') }}?v={{ filemtime(public_path('css/pages/attendance-index-07.css')) }}">
@endpush
@endsection
