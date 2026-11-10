@extends('layouts.app')
@section('title',__('مراجعة بصمات اليوم'))
@section('content')
<a href="{{ route('attendance.exceptions.index') }}">{{ __('استثناءات البصمات') }}</a>
<h3 class="my-3">{{ $day->employee->employee_code }} · {{ $day->employee->full_name }} · {{ $day->work_date->format('d/m/Y') }}</h3>
<p>{{ __('الحالة:') }} {{ ['pending'=>'تحتاج مراجعة','resolved'=>'تمت المراجعة','deleted'=>'مستبعد مع الاحتفاظ بالأصل'][$day->resolution] }}</p>
@if(auth()->user()->hasPermission('attendance.edit'))<a href="{{ route('attendance.exceptions.batch',$day->batch) }}">{{ __('فتح أعمدة الحضور الأصلية:') }} {{ $day->batch->file_name }}</a>@endif
@if($errors->any())<div class="alert alert-danger">{{ implode(' ',$errors->all()) }}</div>@endif
<div class="card p-3 my-3"><h5>{{ __('البصمات الأصلية (لا تتغير)') }}</h5>
@foreach($day->original_punches as $punch)
<div dir="ltr">{{ is_array($punch) ? (($punch['at'] ?? (($punch['slot'] ?? '').' '.($punch['time'] ?? ''))).' · '.($punch['ref'] ?? '')) : $punch }}</div>
@endforeach
</div>
@if(!$editable)<div class="alert alert-info">{{ __('الفترة مقفولة. العرض فقط، لا تصحيح أو حذف.') }}</div>@endif
@if($editable && auth()->user()->hasPermission('attendance.edit') && $day->resolution!=='deleted')
<form method="post" action="{{ route('attendance.exceptions.update',$day) }}" class="card p-3">@csrf @method('PUT')<input type="hidden" name="version" value="{{ $day->revisions->count() }}">
<h5>{{ __('تصحيح البصمات') }}</h5><p class="small text-muted">{{ __('التوقيت كامل بالتاريخ لمراعاة الوردية الليلية. احذف البصمة الزائدة بإزالة صفها. اختر حضور وانصراف صراحةً لاعتماد اليوم، أو اترك الاختيارين فارغين ليظل معلقًا. الحذف والتصحيح يبقيان في سجل التعديل.') }}</p>
<div id="punchRows">
@foreach($day->current_punches as $i=>$punch)
@php
$stamp = is_array($punch) ? (isset($punch['at']) ? \Carbon\Carbon::parse($punch['at'])->format('Y-m-d\TH:i') : $day->work_date->format('Y-m-d').'T'.$punch['time']) : \Carbon\Carbon::parse($punch)->format('Y-m-d\TH:i');
if (is_array($punch) && ($punch['slot'] ?? null)==='out' && isset($day->current_punches[0]['time']) && $punch['time']<$day->current_punches[0]['time']) $stamp=$day->work_date->copy()->addDay()->format('Y-m-d').'T'.$punch['time'];
@endphp
<div class="d-flex gap-2 mb-2 punch-row"><span>{{ $i }}</span><input class="form-control" type="datetime-local" name="punches[]" value="{{ $stamp }}" required><button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove();numberPunches()">{{ __('إزالة') }}</button></div>
@endforeach
</div>
<button type="button" class="btn btn-light mb-3" onclick="addPunch()">{{ __('إضافة بصمة يدويًا') }}</button>
<label>{{ __('بصمة الحضور') }}</label><select name="check_in_index" id="inChoice" class="form-select mb-2"><option value="">{{ __('لم يتم التحديد') }}</option></select>
<label>{{ __('بصمة الانصراف') }}</label><select name="check_out_index" id="outChoice" class="form-select mb-2"><option value="">{{ __('لم يتم التحديد') }}</option></select>
<label>{{ __('سبب التصحيح') }}</label><textarea name="reason" required maxlength="1000" class="form-control mb-2"></textarea>
<label class="mb-3"><input type="checkbox" name="overwrite" value="1"> {{ __('أؤكد تحديث سجل الحضور الموجود، أو حذفه لو تركت اليوم معلقًا (يتطلب صلاحية الحذف).') }}</label>
<button class="btn btn-primary">{{ __('حفظ وإعادة الفحص') }}</button>
</form>
@endif
@if($editable && auth()->user()->hasPermission('attendance.delete') && $day->resolution!=='deleted')
<form method="post" action="{{ route('attendance.exceptions.destroy',$day) }}" class="card p-3 my-3">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $day->revisions->count() }}"><h5>{{ __('استبعاد اليوم') }}</h5><input class="form-control mb-2" name="reason" placeholder="{{ __('سبب الحذف') }}" required maxlength="1000"><label class="mb-2"><input type="checkbox" name="delete_attendance" value="1"> {{ __('أؤكد حذف سجل الحضور المرتبط أيضًا.') }}</label><button class="btn btn-outline-danger">{{ __('استبعاد مع تسجيل السبب') }}</button></form>
@endif
<h5 class="mt-4">{{ __('سجل التعديل') }}</h5>
@forelse($day->revisions as $revision)
<div class="card p-3 mb-2"><strong>{{ $revision->user?->name ?? ($revision->user_id ? 'حساب سابق' : 'مزامنة جهاز البصمة') }} · {{ $revision->created_at->format('d/m/Y H:i') }}</strong><div>{{ $revision->reason }}</div><pre class="small mt-2" dir="ltr" style="white-space:pre-wrap">{{ json_encode(['before'=>$revision->before_data,'after'=>$revision->after_data],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div>
@empty
<p class="text-muted">{{ __('لا توجد تعديلات.') }}</p>
@endforelse
@endsection
@push('scripts')
<script>
function numberPunches(){
 const values=Array.from(document.querySelectorAll('.punch-row input')).map(x=>x.value);
 document.querySelectorAll('.punch-row').forEach((r,i)=>{let s=r.querySelector('span');if(!s){s=document.createElement('span');r.prepend(s);}s.textContent=i+1;});
 for(const id of ['inChoice','outChoice']){const select=document.getElementById(id);if(!select)continue;select.replaceChildren(new Option('لم يتم التحديد',''));values.forEach((v,i)=>select.add(new Option((i+1)+' · '+(v||'توقيت غير مكتمل'),i)));}
}
function addPunch(){const d=document.createElement('div');d.className='d-flex gap-2 mb-2 punch-row';d.innerHTML='<input class="form-control" type="datetime-local" name="punches[]" required><button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove();numberPunches()">إزالة</button>';document.getElementById('punchRows').appendChild(d);numberPunches();}
document.getElementById('punchRows')?.addEventListener('change',numberPunches);numberPunches();
</script>
@endpush
