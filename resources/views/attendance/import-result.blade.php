@extends('layouts.app')
@section('title',__('نتيجة استيراد الحضور'))
@push('styles')<link rel="stylesheet" href="{{ asset('css/import-wizard.css') }}?v=1">@endpush
@section('content')
<div class="odir-import attendance-wizard">
  <div class="wizard-header"><h4>استيراد الحضور والانصراف</h4><div class="import-stepper"><span class="text-success"><b class="step-number">✓</b>رفع الملف</span><span class="text-success"><b class="step-number">✓</b>الأوراق</span><span class="text-success"><b class="step-number">✓</b>المراجعة</span><span class="text-primary"><b class="step-number">4</b>النتيجة</span></div></div>
  <div class="wizard-body">
    <div class="file-chip mb-3">{{ $report['fileName'] }}</div>
    <div class="import-success"><i class="bi bi-check-circle-fill"></i><div><strong>اكتمل تنفيذ الاستيراد</strong><div class="small">{{ $report['message'] }}</div></div></div>
    <div class="import-stats">
      <div class="import-stat good"><span>سجلات مستوردة</span><strong>{{ $report['imported'] }}</strong></div>
      <div class="import-stat blue"><span>أيام للمراجعة</span><strong>{{ $report['deferred'] }}</strong></div>
      <div class="import-stat bad"><span>تعذر حفظها</span><strong>{{ $report['failed'] }}</strong></div>
      <div class="import-stat neutral"><span>رسائل تحقق / مراجعة</span><strong>{{ $report['error_count'] }}</strong></div>
    </div>
    <h6>ملاحظات مهمة</h6>
    <div class="import-note mb-3">تقرير التنزيل يضم كل رسائل الرفض والمراجعة، وليس أول ٢٥ فقط. التقرير متاح لصاحب الرفع في شركته لمدة ٣٠ دقيقة، وسجل الاستيراد محفوظ للمراجعة.</div>
    @if(!empty($report['warnings']))<div class="alert alert-warning small"><ul class="mb-0">@foreach($report['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif
    @if($report['errors'])<ul class="small text-danger">@foreach($report['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    @if($report['error_count']>25)<div class="small text-muted mb-3">المعروض أول ٢٥ رسالة. حمّل التقرير لرؤية الكل.</div>@endif
    <div class="d-flex flex-wrap gap-2">
      @if($report['error_count'])<a class="btn btn-outline-danger" href="{{ route('attendance.import.result.rejected',$reportToken) }}">تحميل المرفوضات والمراجعة (Excel)</a>@endif
      @if(auth()->user()->hasPermission('attendance.view'))<a class="btn btn-primary" href="{{ route('attendance.exceptions.index') }}">فتح صفحة مراجعة الاستيراد</a>@endif
      <a class="btn btn-light" href="{{ route('attendance.index') }}">عرض الحضور / استيراد ملف آخر</a>
    </div>
  </div>
</div>
@endsection
