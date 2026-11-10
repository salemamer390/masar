@extends('layouts.app')
@section('title',__('أصل ملف الاستيراد'))
@section('content')
<a href="{{ route('attendance.exceptions.index') }}">{{ __('الاستثناءات') }}</a><h3 class="my-3">{{ $batch->file_name }}</h3><p>{{ __('نسخة أصلية خاصة للمراجعة، لا تتغير عند تصحيح البصمات.') }}</p>
<h5>{{ __('أيام الملف وسجل المراجعة') }}</h5>
@foreach(\App\Models\AttendanceImportDay::with('employee')->where('batch_id',$batch->id)->orderBy('work_date')->get() as $importDay)
<div class="mb-2"><a href="{{ route('attendance.exceptions.show',$importDay) }}">{{ $importDay->employee->employee_code }} · {{ $importDay->work_date->format('d/m/Y') }}</a> · {{ ['pending'=>'تحتاج مراجعة','resolved'=>'تمت المراجعة','deleted'=>'مستبعد'][$importDay->resolution] }}</div>
@endforeach
@foreach(($batch->original_data['errors'] ?? []) as $error)
<div class="alert alert-warning">{{ __('صف يحتاج مراجعة في مصدر الاستيراد. راجع المعاينة أو الملف الأصلي الخاص.') }}</div>
@endforeach
<p class="small text-muted">{{ __('المعروض أعمدة الحضور المعيّنة فقط. بقية خلايا الملف محفوظة خاصة ولا تُعرض هنا.') }}</p>
@foreach($mappedSheets as $sheet)
<h5>{{ $sheet['name'] ?? 'ورقة' }}</h5><div class="table-responsive" style="max-height:500px"><table class="table table-sm table-bordered"><tbody>
@foreach(($sheet['rows'] ?? []) as $line=>$row)
<tr><th>{{ $line }}</th>@foreach($row as $cell)<td style="white-space:nowrap">{{ $cell }}</td>@endforeach</tr>
@endforeach
</tbody></table></div>
@endforeach
@endsection
