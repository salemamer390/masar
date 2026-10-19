@extends('layouts.app')
@section('title','الحركات الوظيفية')
@section('content')
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET"><select name="employee_id" class="form-select"><option value="">كل الموظفين</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><select name="movement_type_id" class="form-select"><option value="">كل أنواع الحركات</option>@foreach($movementTypes as $movementType)<option value="{{ $movementType->id }}" @selected(request('movement_type_id') == $movementType->id)>{{ $movementType->name }}</option>@endforeach</select><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="من تاريخ"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="إلى تاريخ"><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('movements.index') }}">مسح</a></form>
<div class="page-head"><div><h1>الحركات الوظيفية</h1><p>ترقيات ونقل الأقسام وتغيير الوظائف والرواتب مع حفظ السجل.</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{route('master.index',['type'=>'movements'])}}"><i class="bi bi-gear"></i> إعدادات أنواع الحركات</a>@endif @if(auth()->user()?->hasPermission('movements.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#movementModal"><i class="bi bi-plus-lg"></i> تسجيل حركة</button>@endif</div></div>
<div class="panel table-panel"><table class="table align-middle"><thead><tr><th>التاريخ</th><th>الموظف</th><th>نوع الحركة</th><th>من القسم</th><th>إلى القسم</th><th>من الوظيفة</th><th>إلى الوظيفة</th><th>الراتب الجديد</th></tr></thead><tbody>@forelse($movements as $m)<tr><td>{{$m->movement_date?->format('d/m/Y')}}</td><td><a class="employee-link fw-bold" href="{{route('employees.show',$m->employee)}}">{{$m->employee?->full_name}}</a></td><td>{{$m->movementType?->name??'—'}}</td><td>{{$m->oldDepartment?->name??'—'}}</td><td>{{$m->newDepartment?->name??'—'}}</td><td>{{$m->oldJob?->title??'—'}}</td><td>{{$m->newJob?->title??'—'}}</td><td>{{format_number($m->new_salary??0)}} ج.م</td></tr>@empty<tr><td colspan="8" class="text-center py-5">لا توجد حركات.</td></tr>@endforelse</tbody></table></div>
<x-pagination-controls :paginator="$movements" />
<script>
document.addEventListener('DOMContentLoaded', function () { (function () {
    const form = document.querySelector('#movementModal form');
    const tbody = document.querySelector('.table-panel tbody');
    if (!form) return;
    form.dataset.ajax = 'true';
    form.dataset.ajaxLabel = 'جارٍ الحفظ';
    const dateLabel = value => { const parts = String(value || '').slice(0, 10).split('-'); return parts.length === 3 ? [parts[2], parts[1], parts[0]].join('/') : '—'; };
    document.addEventListener('masar:success', event => {
        if (event.target !== form || !tbody || !event.detail?.movement) return;
        const movement = event.detail.movement;
        tbody.querySelector('td[colspan]')?.closest('tr')?.remove();
        const row = document.createElement('tr');
        [dateLabel(movement.movement_date), movement.employee?.full_name, movement.movement_type?.name, movement.old_department?.name, movement.new_department?.name, movement.old_job?.title, movement.new_job?.title, String(movement.new_salary ?? 0) + ' ج.م'].forEach(value => { const cell = document.createElement('td'); cell.textContent = value ?? '—'; row.appendChild(cell); });
        const employeeCell = row.children[1];
        if (movement.employee?.id) { employeeCell.innerHTML = ''; const link = document.createElement('a'); link.className = 'employee-link fw-bold'; link.href = '/employees/' + movement.employee.id; link.textContent = movement.employee.full_name || '—'; employeeCell.appendChild(link); }
        tbody.prepend(row);
        bootstrap.Modal.getInstance(document.getElementById('movementModal'))?.hide();
        form.reset();
    });
})(); });
</script>
<div class="modal fade" id="movementModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('movements.store')}}">@csrf<div class="modal-header"><h5>تسجيل حركة وظيفية</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($employees as $e)<option value="{{$e->id}}">{{$e->full_name}}</option>@endforeach</select><label class="form-label">نوع الحركة</label><select name="movement_type_id" class="form-select mb-3" required>@foreach($movementTypes as $t)<option value="{{$t->id}}">{{$t->name}}</option>@endforeach</select><label class="form-label">تاريخ التنفيذ</label><input type="date" name="movement_date" value="{{date('Y-m-d')}}" class="form-control mb-3" required><div class="row g-2"><div class="col-6"><label class="form-label">القسم الجديد</label><select name="new_department_id" class="form-select"><option value="">بدون تغيير</option>@foreach($departments as $d)<option value="{{$d->id}}">{{$d->name}}</option>@endforeach</select></div><div class="col-6"><label class="form-label">الوظيفة الجديدة</label><select name="new_job_id" class="form-select"><option value="">بدون تغيير</option>@foreach($jobs as $j)<option value="{{$j->id}}">{{$j->title}}</option>@endforeach</select></div></div><label class="form-label mt-3">الراتب الجديد</label><input type="number" step="0.01" name="new_salary" class="form-control"><label class="form-label mt-3">ملاحظات</label><textarea name="notes" class="form-control"></textarea></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ الحركة</button></div></form></div></div></div>
@endsection
