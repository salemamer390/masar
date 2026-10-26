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
  #payrollPrototype .drawer .profile { position:relative; }
  #payrollPrototype .drawer .profile > .close { position:absolute; top:10px; left:10px; z-index:2; }
  #payrollPrototype .salary > h4 { justify-content:center; }
  #payrollPrototype .drawer .profile { margin:12px 14px 14px; padding:14px 16px; }
  #payrollPrototype .drawer .section { padding:0 14px 14px; }
  #payrollPrototype .actions { display:flex; gap:8px; flex-wrap:wrap; }
  #payrollPrototype .actions .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-weight:800; transition:filter .15s ease, transform .15s ease; }
  #payrollPrototype .actions .btn:hover { filter:brightness(.94); }
  #payrollPrototype .actions .btn:active { transform:translateY(1px); }
  #payrollPrototype .actions .btn i { font-size:15px; line-height:1; }
  #payrollPrototype .panelhead > .employee-payroll-actions { margin:0 !important; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  @media (max-width:850px) {
    #payrollPrototype .panelhead > .employee-payroll-actions { width:100%; }
    #payrollPrototype .panelhead > .employee-payroll-actions .btn { flex:1; }
  }
  @media print {
    body.printing-payslip * { visibility:hidden !important; }
    body.printing-payslip #payrollPrototype .drawer,
    body.printing-payslip #payrollPrototype .drawer * { visibility:visible !important; }
    body.printing-payslip #payrollPrototype .drawer { position:absolute !important; inset:0 !important; width:100% !important; max-width:none !important; height:auto !important; overflow:visible !important; transform:none !important; box-shadow:none !important; }
    body.printing-payslip #payrollPrototype .drawer-overlay,
    body.printing-payslip #payrollPrototype .actions,
    body.printing-payslip #payrollPrototype .close { display:none !important; }
    body.printing-payslip { background:#fff !important; }
  }
  #payrollPrototype .actions .btn-pdf { background:#dc3545; border-color:#dc3545; color:#fff; }
  #payrollPrototype .actions .btn-print { background:#f3f5f8; border-color:#cbd5e1; color:#334155; }
  #payrollPrototype .actions .btn-whatsapp { background:#25d366; border-color:#25d366; color:#fff; }
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
  stats: @json($prototypeStats),
  monthIndex: {{ $monthIndex }},
  year: {{ $month->format('Y') }},
  pdfUrlTemplate: @json(route('payroll.employee-salaries.payslip-pdf', ['payrollRecord' => '__RECORD__']))
};
</script>
<script src="{{ asset('payroll/employee-salaries.js') }}"></script>
@endsection
