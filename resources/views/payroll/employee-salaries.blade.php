@extends('layouts.app')

@section('title', 'رواتب الموظفين')

@section('content')
@php
    $pageBody = view('payroll.partials.employee-salaries-body')->render();
    $peopleJson = json_encode($prototypePeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $monthIndex = max(0, min(11, (int) $month->format('n') - 1));
@endphp

<link rel="stylesheet" href="{{ asset('payroll/employee-salaries.css') }}">
<style>
  .employee-payroll-actions .btn-success { background:#16a36f; border-color:#16a36f; color:#fff; }
  .employee-payroll-actions .btn-success:hover { background:#118b5e; border-color:#118b5e; color:#fff; }
  .employee-payroll-actions .btn-outline-secondary { background:#fff; border-color:#aeb9c9; color:#52627a; }
  .employee-payroll-actions .btn-primary { background:#1677f9; border-color:#1677f9; color:#fff; }
  .employee-payroll-actions .btn-primary:hover { background:#0e63d6; border-color:#0e63d6; color:#fff; }
</style>

<div class="employee-payroll-actions d-flex gap-2 flex-wrap mb-3">
  @if(auth()->user()?->hasPermission('payroll.export'))
    <a class="btn btn-success" href="{{ route('payroll.export', ['month' => $month->format('Y-m-01'), 'format' => 'csv']) }}"><i class="bi bi-file-earmark-excel"></i> تصدير Excel</a>
  @endif
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
  @if(auth()->user()?->hasPermission('payroll.create'))
    <form method="POST" action="{{ route('payroll.runs.generate') }}" class="d-inline">
      @csrf
      <input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}">
      <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> تحديث المسير</button>
    </form>
  @endif
</div>

<div id="payrollPrototype" class="payroll-prototype-page">{!! $pageBody !!}</div>
<script>
window.masarEmployeeSalaries = {
  people: {!! $peopleJson !!},
  monthIndex: {{ $monthIndex }},
  year: {{ $month->format('Y') }},
  pdfUrlTemplate: @json(route('payroll.employee-salaries.payslip-pdf', ['payrollRecord' => '__RECORD__']))
};
</script>
<script src="{{ asset('payroll/employee-salaries.js') }}"></script>
@endsection
