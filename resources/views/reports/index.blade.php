@extends('layouts.app')
@section('title', 'التقارير')
@section('content')
<div class="page-head"><div><h1>التقارير</h1><p>كل تقرير يعتمد على سجلات قاعدة البيانات ويمكن تصديره من صفحة التقرير.</p></div></div>
<div class="report-grid">@foreach([
['employees','تقرير الموظفين','بيانات الموظفين والأقسام والحالات','people-fill'],
['attendance','تقرير الحضور','الحضور والانصراف والتأخير','clock-history'],
['salaries','تقرير هياكل الرواتب','الهياكل النشطة والاستحقاقات','cash-stack'],
['payroll','تقرير مسير الرواتب','السجلات المعتمدة والمقفلة فقط','receipt-cutoff'],
['leaves','تقرير الإجازات','الطلبات والأرصدة وحالات الاعتماد','calendar2-check'],
['insurance','تقرير التأمينات','التسجيل والأكواد والحالة','shield-check'],
['summary','الملخص الإداري','مؤشرات الموارد البشرية الأساسية','bar-chart-fill']
] as $report)<a class="report-card text-decoration-none" href="{{ route('reports.show',$report[0]) }}"><i class="bi bi-{{ $report[3] }}"></i><b>{{ $report[1] }}</b><span>{{ $report[2] }}</span></a>@endforeach</div>
@endsection
