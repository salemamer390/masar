@extends('layouts.app')
@section('title','استثناءات البصمات')
@section('content')
<div class="d-flex justify-content-between mb-3"><h3>استثناءات البصمات <span class="badge bg-warning text-dark">{{ $count }}</span></h3><a class="btn btn-light" href="{{ route('attendance.index') }}">الحضور</a></div>
<p class="text-muted">البصمات الأصلية محفوظة منفصلة. البصمات الزائدة لا تتحول تلقائيًا إلى حضور وانصراف. لا يتم اختراع ميعاد للبصمة الناقصة.</p>
<form method="get" class="d-flex gap-2 mb-3"><input class="form-control" type="date" name="from" value="{{ request('from') }}" aria-label="من تاريخ"><input class="form-control" type="date" name="to" value="{{ request('to') }}" aria-label="إلى تاريخ"><button class="btn btn-primary">عرض</button></form>
<div class="table-responsive"><table class="table"><thead><tr><th>الكود</th><th>الموظف</th><th>التاريخ</th><th>سبب المراجعة</th><th>البصمات</th><th></th></tr></thead><tbody>
@forelse($days as $day)
<tr><td>{{ $day->employee->employee_code }}</td><td>{{ $day->employee->full_name }}</td><td>{{ $day->work_date->format('d/m/Y') }}</td><td>{{ $day->issue==='extra' ? 'بصمات زائدة' : 'بصمة ناقصة / تحتاج تحديد' }}</td><td>{{ count($day->current_punches) }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('attendance.exceptions.show',$day) }}">فتح</a></td></tr>
@empty
<tr><td colspan="6">لا توجد استثناءات معلقة.</td></tr>
@endforelse
</tbody></table></div>{{ $days->links() }}
<h5 class="mt-4">ملفات الاستيراد الأخيرة</h5><p class="small text-muted">كل ملف يحتفظ بالأوراق المختارة وأخطاء الصفوف الأصلية، حتى لو لم يُنشأ لها سجل يومي.</p>
@foreach($batches as $batch)
<div class="border rounded p-2 mb-2">@if(auth()->user()->hasPermission('attendance.edit'))<a href="{{ route('attendance.exceptions.batch',$batch) }}">{{ $batch->file_name }}</a>@else{{ $batch->file_name }}@endif <small>{{ $batch->created_at->format('d/m/Y H:i') }}</small></div>
@endforeach
@endsection
