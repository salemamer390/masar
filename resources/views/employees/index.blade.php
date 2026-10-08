@extends('layouts.app')

@section('title', 'الموظفون')

@section('content')
<div class="page-head">
  <div>
    <h1>الموظفون</h1>
    <p>إدارة ملف الموظف والبيانات الأساسية.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    @if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{ route('master.index',['type'=>'departments']) }}"><i class="bi bi-gear"></i> إعدادات بيانات الموظفين</a>@endif
    @if(auth()->user()?->hasPermission('employees.create'))
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
      <i class="bi bi-plus-lg"></i> إضافة موظف
    </button>
    @endif
  </div>
</div>
@php($activeStatusId = $statuses->firstWhere('name', 'Active')?->id)
<div class="employee-kpi-grid">
  @foreach([
    ['إجمالي الموظفين', $employeeSummary['total'], $employeeSummary['inactive'] ? $employeeSummary['active'].' نشط · '.$employeeSummary['inactive'].' غير نشط' : 'جميع الموظفين نشطون', 'blue', 'people-fill', route('employees.index')],
    ['الموظفون النشطون', $employeeSummary['active'], 'من إجمالي '.$employeeSummary['total'].' موظف', 'green', 'person-check-fill', route('employees.index', array_filter(['status_id' => $activeStatusId]))],
    ['عدد الأقسام', $employeeSummary['departments'], $employeeSummary['unassigned'] ? $employeeSummary['unassigned'].' موظف دون قسم' : 'الأقسام التي بها موظفون حاليًا', 'purple', 'diagram-3-fill', route('employees.index')],
    ['عقود تنتهي قريبًا', $employeeSummary['contracts_due'], 'حتى '.today()->copy()->addDays(30)->format('d/m/Y'), 'orange', 'calendar2-x-fill', route('employees.index')],
    ['غير مؤمن عليهم', $employeeSummary['uninsured'], 'موظفون بلا سجل تأمين', 'red', 'shield-x', route('insurance.index')],
    ['في إجازة حاليًا', $employeeSummary['on_leave'], 'إجازات معتمدة تشمل اليوم', 'blue', 'calendar2-check-fill', route('leaves.index')],
  ] as $stat)
  <a href="{{ $stat[5] }}" class="employee-kpi {{ $stat[3] }}">
    <span class="employee-kpi-icon"><i class="bi bi-{{ $stat[4] }}"></i></span>
    <span><b>{{ $stat[0] }}</b><strong>{{ $stat[1] }}</strong><small>{{ $stat[2] }}</small></span>
  </a>
  @endforeach
</div>
<form method="GET" action="{{ route('employees.index') }}" class="filter-bar">
  <div class="search-table">
    <i class="bi bi-search"></i>
    <input name="search" value="{{ request('search') }}" placeholder="بحث باسم الموظف أو الكود...">
  </div>
  <select name="department_id" onchange="this.form.submit()">
    <option value="">كل الأقسام</option>
    @foreach($departments as $dept)
      <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
    @endforeach
  </select>
  <select name="status_id" onchange="this.form.submit()">
    <option value="">كل الحالات</option>
    @foreach($statuses as $st)
      <option value="{{ $st->id }}" {{ request('status_id') == $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
    @endforeach
  </select>
  @if(request()->anyFilled(['search', 'department_id', 'status_id']))
    <a href="{{ route('employees.index') }}" class="btn btn-light d-flex align-items-center"><i class="bi bi-arrow-counterclockwise"></i></a>
  @endif
</form>

<div class="panel table-panel">
  <table class="table align-middle">
    <thead>
      <tr>
        <th>الكود</th>
        <th>الموظف</th>
        <th>الوظيفة</th>
        <th>القسم</th>
        <th>الحالة</th>
        <th>تاريخ التعيين</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse($employees as $employee)
        <tr>
          <td>#{{ $employee->employee_code }}</td>
          <td>
            <div class="employee-cell">
              <span class="employee-avatar">@if($employee->profile_photo_url)<img src="{{ $employee->profile_photo_url }}" alt="{{ $employee->full_name }}">@else{{ $employee->initials }}@endif</span>
              <a href="{{ route('employees.show', $employee) }}" class="employee-link text-decoration-none fw-bold">
                {{ $employee->full_name }}
              </a>
            </div>
          </td>
          <td>{{ $employee->job?->title ?? '—' }}</td>
          <td>{{ $employee->department?->name ?? '—' }}</td>
          <td>
            @php($employeeStatus = $employee->status?->name ?? 'Active')
            @if(auth()->user()?->hasPermission('employees.edit'))
            <div class="dropdown">
              <button type="button" class="badge-soft border-0 {{ match($employeeStatus) { 'Leave', 'Pending' => 'warning', 'Suspended', 'Terminated' => 'danger', default => 'success' } }} dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                {{ match($employeeStatus) { 'Active' => 'نشط', 'Leave' => 'إجازة', 'Suspended' => 'موقوف', 'Terminated' => 'منتهي الخدمة', 'Pending' => 'قيد التعيين', default => $employeeStatus } }}
              </button>
              <ul class="dropdown-menu shadow-sm border-0 p-2">
                @foreach($statuses as $status)
                  @php($statusTone = match($status->name) { 'Leave', 'Pending' => 'warning', 'Suspended', 'Terminated' => 'danger', default => 'success' })
                  <li><form method="POST" action="{{ route('employees.update', $employee) }}" onsubmit="return confirm('تأكيد تغيير حالة الموظف إلى: {{ $status->name }}؟')">@csrf @method('PUT')<input type="hidden" name="full_name" value="{{ $employee->full_name }}"><input type="hidden" name="status_id" value="{{ $status->id }}"><button class="dropdown-item rounded-2 d-flex align-items-center gap-2" @disabled($employee->status_id == $status->id)><span class="badge-soft {{ $statusTone }}">{{ match($status->name) { 'Active' => 'نشط', 'Leave' => 'إجازة', 'Suspended' => 'موقوف', 'Terminated' => 'منتهي الخدمة', 'Pending' => 'قيد التعيين', default => $status->name } }}</span>@if($employee->status_id == $status->id)<i class="bi bi-check-lg ms-auto"></i>@endif</button></form></li>
                @endforeach
              </ul>
            </div>
            @else
            <span class="badge-soft {{ match($employeeStatus) { 'Leave' => 'warning', 'Suspended', 'Terminated' => 'danger', default => 'success' } }}">
              {{ match($employeeStatus) { 'Active' => 'نشط', 'Leave' => 'إجازة', 'Suspended' => 'موقوف', 'Terminated' => 'منتهي الخدمة', 'Pending' => 'قيد التعيين', default => $employeeStatus } }}
            </span>
            @endif
          </td>
          <td>{{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</td>
          <td>
            <div class="dropdown">
              <button class="table-action" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                <li><a class="dropdown-item py-2" href="{{ route('employees.show', $employee) }}"><i class="bi bi-person me-2"></i> عرض الملف الكامل</a></li>
                @if(auth()->user()?->hasPermission('employees.edit'))
                <li><a class="dropdown-item py-2" href="{{ route('employees.show', [$employee, 'tab' => 'job']) }}#employment-status"><i class="bi bi-person-gear me-2"></i> تعديل حالة الموظف</a></li>
                @endif
                <li><a class="dropdown-item py-2" href="{{ route('employees.show', [$employee, 'tab' => 'documentsProfile']) }}"><i class="bi bi-folder me-2"></i> مسوغات التعيين</a></li>
                <li><a class="dropdown-item py-2" href="{{ route('employees.print', $employee) }}" target="_blank"><i class="bi bi-printer me-2"></i> طباعة الملف</a></li>
              </ul>
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="text-center text-muted py-5">لا توجد بيانات موظفين مطابقة للبحث.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

@if($employees->hasPages())
  <div class="d-flex justify-content-center mt-4">
    {{ $employees->links() }}
  </div>
@endif

<!-- Add Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">إضافة موظف جديد</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="{{ route('employees.store') }}" method="POST" id="addEmployeeForm">
        @csrf
        <input type="hidden" name="salary_source" id="new_employee_salary_source" value=""><input type="hidden" name="salary_value" id="new_employee_salary_value" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">الاسم الكامل <span class="text-danger">*</span></label>
            <input type="text" name="full_name" class="form-control" placeholder="مثال: أحمد محمد علي" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">كود الموظف</label>
              <input type="text" name="employee_code" class="form-control" value="{{ old('employee_code') }}" placeholder="اختياري، مثل 101">
              <div class="form-text">اختياري: أدخل كودًا خاصًا، أو اتركه فارغًا ليُولّده النظام تلقائيًا.</div>
            </div>
            <div class="col-6">
              <label class="form-label">الرقم القومي</label>
              <input type="text" name="national_id" class="form-control" placeholder="14 رقم">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">القسم</label>
              <select name="department_id" class="form-select">
                <option value="">اختر القسم...</option>
                @foreach($departments as $dept)
                  <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">الوظيفة</label>
              <select name="job_id" class="form-select">
                <option value="">اختر الوظيفة...</option>
                @foreach(\App\Models\Job::all() as $jb)
                  <option value="{{ $jb->id }}">{{ $jb->title }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">الراتب الإجمالي</label>
              <input type="number" id="new_employee_gross" class="form-control employee-salary-input" min="0.01" step="0.01" placeholder="مثال: 28118.56">
              <div class="form-text">أدخل الإجمالي ليُحسب الصافي فورًا.</div>
            </div>
            <div class="col-6">
              <label class="form-label">الراتب الصافي</label>
              <input type="number" id="new_employee_net" class="form-control employee-salary-input" min="0.01" step="0.01" placeholder="مثال: 22000">
              <div class="form-text">أو أدخل الصافي ليُحسب الإجمالي فورًا. يُحفظ الإجمالي فقط.</div>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">رقم الهاتف</label>
              <input type="text" name="phone" class="form-control" placeholder="01xxxxxxxxx">
            </div>
            <div class="col-6">
              <label class="form-label">البريد الإلكتروني</label>
              <input type="email" name="email" class="form-control" placeholder="name@company.com">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">تاريخ التعيين / بداية العقد</label>
              <input id="new_employee_hire_date" type="date" name="hire_date" class="form-control" value="{{ old('hire_date', date('Y-m-d')) }}">
            </div>
            <div class="col-6">
              <label class="form-label">تاريخ نهاية العقد</label>
              <input id="new_employee_contract_end_date" type="date" name="contract_end_date" class="form-control" value="{{ old('contract_end_date', now()->addYear()->format('Y-m-d')) }}">
              <div class="form-text">يُضبط تلقائيًا لسنة ويمكن تعديله.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> حفظ الموظف</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const hireDateInput = document.getElementById('new_employee_hire_date');
    const contractEndInput = document.getElementById('new_employee_contract_end_date');
    let contractEndEdited = false;
    function oneYearAfter(value) {
        if (!value) return '';
        const date = new Date(value + 'T00:00:00');
        date.setFullYear(date.getFullYear() + 1);
        return date.toISOString().slice(0, 10);
    }
    contractEndInput?.addEventListener('input', () => { contractEndEdited = true; });
    hireDateInput?.addEventListener('change', () => {
        if (!contractEndEdited) contractEndInput.value = oneYearAfter(hireDateInput.value);
        contractEndInput.min = hireDateInput.value;
    });
    if (hireDateInput && contractEndInput) contractEndInput.min = hireDateInput.value;

    const grossInput = document.getElementById('new_employee_gross');
    const netInput = document.getElementById('new_employee_net');
    const sourceInput = document.getElementById('new_employee_salary_source');
    const form = document.getElementById('addEmployeeForm');
    if (!grossInput || !netInput || !sourceInput || !form) return;

    const endpoint = @json(route('employees.salary-preview.get'));
    let sequence = 0;
    let lastSource = null;
    let requestController = null;

    async function sync(source) {
        const input = source === 'gross' ? grossInput : netInput;
        const otherInput = source === 'gross' ? netInput : grossInput;
        const value = input.value.trim();
        sourceInput.value = source;
        if (value && Number(value) > 0) {
            document.getElementById('new_employee_salary_value').value = value;
        } else {
            document.getElementById('new_employee_salary_value').value = '';
        }

        if (requestController) requestController.abort();
        if (!value || Number(value) <= 0) {
            otherInput.value = '';
            lastSource = null;
            return;
        }

        lastSource = source;
        const current = ++sequence;
        requestController = new AbortController();
        try {
            const url = endpoint + '?salary_source=' + encodeURIComponent(source) + '&salary_value=' + encodeURIComponent(value);
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: requestController.signal
            });
            if (!response.ok || current !== sequence) return;
            const result = await response.json();
            otherInput.value = Number(source === 'gross' ? result.net : result.gross).toFixed(2);
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Salary preview failed', error);
        }
    }

    grossInput.addEventListener('input', () => sync('gross'));
    netInput.addEventListener('input', () => sync('net'));
    grossInput.addEventListener('change', () => sync('gross'));
    netInput.addEventListener('change', () => sync('net'));

    form.addEventListener('submit', function () {
        const active = lastSource;
        if (active) {
            sourceInput.value = active;
            document.getElementById('new_employee_salary_value').value = active === 'gross' ? grossInput.value : netInput.value;
        }
    });
})();
</script>
@endpush
