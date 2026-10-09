@extends('layouts.app')
@section('title','البيانات الوظيفية')
@section('content')
<form class="filter-bar d-flex flex-wrap gap-2 mb-3" method="GET"><div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="بحث بالاسم أو الكود"></div><select name="department_id" class="form-select"><option value="">كل الأقسام</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select><select name="contract_status" class="form-select"><option value="">كل العقود</option><option value="active" @selected(request('contract_status') === 'active')>عقود سارية</option><option value="expired" @selected(request('contract_status') === 'expired')>عقود منتهية</option></select><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="{{ route('jobs.index') }}">مسح</a></form>
<div class="page-head"><div><h1>البيانات الوظيفية</h1><p>الأقسام والوظائف والفروع والعقود الحالية للموظفين.</p></div>@if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{route('master.index',['type'=>'jobs'])}}"><i class="bi bi-gear"></i> إعدادات البيانات الوظيفية</a>@endif</div>
<div class="stat-grid compact">
@foreach([['إجمالي الوظائف',$totalJobs,'blue','briefcase'],['الأقسام',$totalDepartments,'green','diagram-3'],['الفروع',$totalBranches,'orange','building'],['العقود النشطة',$activeContracts,'purple','file-earmark-text']] as $s)
<div class="stat-card"><div class="stat-icon {{$s[2]}}"><i class="bi bi-{{$s[3]}}"></i></div><div><span>{{$s[0]}}</span><b>{{$s[1]}}</b><small>بيانات النظام</small></div></div>
@endforeach
<div class="stat-card"><div class="stat-icon red"><i class="bi bi-calendar-x"></i></div><div><span>عقود تنتهي خلال 30 يومًا</span><b>{{ $contractsDueSoon }}</b><small>تنبيه استباقي</small></div></div>
</div>
<div class="panel table-panel"><div class="panel-head"><div><h3>بيانات الموظفين الوظيفية</h3><span>الوظيفة والقسم والفرع ونوع العقد</span></div></div>
<table class="table align-middle"><thead><tr><th>الموظف</th><th>الوظيفة</th><th>القسم</th><th>الفرع</th><th>نوع التوظيف</th><th>بداية العقد</th><th>نهاية العقد</th></tr></thead><tbody>
@forelse($employees as $e)<tr><td><a class="employee-link fw-bold" href="{{route('employees.show',$e)}}">{{$e->full_name}}</a><div class="text-muted small">{{$e->employee_code}}</div></td><td>{{$e->job?->title??'—'}}</td><td>{{$e->department?->name??'—'}}</td><td>{{$e->branch?->name??'—'}}</td><td>{{$e->employmentType?->name??'—'}}</td><td>{{$e->contract_start_date?->format('d/m/Y')??$e->hire_date?->format('d/m/Y')??'—'}}</td><td>{{$e->contract_end_date?->format('d/m/Y')??'—'}}</td></tr>@empty<tr><td colspan="7" class="text-center py-5">لا توجد بيانات.</td></tr>@endforelse
</tbody></table></div>
@endsection
