@extends('layouts.app')
@section('title', __('التقارير'))
@section('content')
<div class="page-head"><div><h1>{{ __('التقارير') }}</h1><p>{{ __('كل تقرير يعتمد على سجلات قاعدة البيانات ويمكن تصديره من صفحة التقرير.') }}</p></div></div>
<div class="report-grid">@foreach([
['employees',__('تقرير الموظفين'),__('بيانات الموظفين والأقسام والحالات'),'people-fill'],
['attendance',__('تقرير الحضور'),__('الحضور والانصراف والتأخير'),'clock-history'],
['salaries',__('تقرير هياكل الرواتب'),__('الهياكل النشطة والاستحقاقات'),'cash-stack'],
['payroll',__('تقرير مسير الرواتب'),__('السجلات المعتمدة والمقفلة فقط'),'receipt-cutoff'],
['leaves',__('تقرير الإجازات'),__('الطلبات والأرصدة وحالات الاعتماد'),'calendar2-check'],
['insurance',__('تقرير التأمينات'),__('التسجيل والأكواد والحالة'),'shield-check'],
['summary',__('الملخص الإداري'),__('مؤشرات الموارد البشرية الأساسية'),'bar-chart-fill']
] as $report)
@php
    $reportUrl = ($report[0] === 'payroll' && auth()->user()?->hasPermission('reports.export_excel'))
        ? route('reports.export', ['type' => 'payroll', 'month' => now()->format('Y-m-01')])
        : route('reports.show', $report[0]);
@endphp
<a class="report-card text-decoration-none" href="{{ $reportUrl }}"><i class="bi bi-{{ $report[3] }}"></i><b>{{ $report[1] }}</b><span>{{ $report[2] }}</span></a>
@endforeach</div>
@endsection
