@extends('layouts.app')

@section('title', __('رواتب الموظفين'))

@section('content')
@php
    $pageBody = view('payroll.partials.employee-salaries-body')->render();
    $peopleJson = json_encode($prototypePeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $monthIndex = max(0, min(11, (int) $month->format('n') - 1));
    $arabicMonthLabel = $month->locale(app()->getLocale())->translatedFormat('F Y');
@endphp

<link rel="stylesheet" href="{{ asset('payroll-assets/employee-salaries.css') }}">
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
  .payroll-view-tabs { display:none !important; }
  .payroll-view-tabs .nav-link { font-weight:800; color:#52627a; }
  .payroll-view-tabs .nav-link.active { color:#1677f9; }
  #payrollPrototype .leftpanel .panelhead .payroll-inline-tabs { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
  #payrollPrototype .leftpanel .panelhead .payroll-inline-tabs .nav-link { border:1px solid transparent; border-radius:9px; padding:8px 11px; font-weight:800; font-size:12px; color:#52627a; }
  #payrollPrototype .leftpanel .panelhead .payroll-inline-tabs .nav-link.active { background:#edf5ff; border-color:#cfe1ff; color:#1677f9; }
  .payroll-view-banner { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; background:#eef5ff; border:1px solid #cfe1ff; color:#0e4fb3; border-radius:12px; padding:10px 14px; margin-bottom:12px; font-weight:700; }
  .payroll-history-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:12px; }
  .payroll-history-card { all:unset; box-sizing:border-box; cursor:pointer; display:block; background:#fff; border:1px solid #e1e8f2; border-radius:14px; padding:14px 16px; transition:box-shadow .15s ease, transform .15s ease, border-color .15s ease; }
  .payroll-history-card:hover, .payroll-history-card:focus-visible { border-color:#1677f9; box-shadow:0 6px 18px rgba(22,119,249,.14); transform:translateY(-2px); }
  .payroll-history-card.is-current { border-color:#1677f9; background:#f5f9ff; }
  .payroll-history-card h4 { margin:0 0 8px; font-size:17px; font-weight:800; color:#1b2a41; display:flex; justify-content:space-between; align-items:center; gap:8px; }
  .payroll-history-card .row-line { display:flex; justify-content:space-between; font-size:13px; color:#64748b; margin-top:5px; }
  .payroll-history-card .row-line b { color:#1b2a41; }
  .payroll-history-card .row-line b.net { color:#0969e8; }
  .payroll-status { font-size:11px; font-weight:800; padding:3px 9px; border-radius:999px; background:#eef2f7; color:#475569; }
  .payroll-status.s-Approved { background:#e3f6ee; color:#07845f; }
  .payroll-status.s-Locked { background:#fde8ea; color:#c0293b; }
  .payroll-status.s-Draft { background:#fff4dc; color:#a76a00; }
  .payroll-month-picker { position:fixed; z-index:2000; width:280px; background:#fff; border:1px solid #d9e2ef; border-radius:14px; box-shadow:0 14px 36px rgba(15,23,42,.18); padding:12px; direction:rtl; }
  .payroll-month-picker .mp-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; font-weight:800; }
  .payroll-month-picker .mp-head button { border:0; background:#f1f5f9; border-radius:8px; width:30px; height:30px; cursor:pointer; }
  .payroll-month-picker .mp-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
  .payroll-month-picker .mp-month { position:relative; border:1px solid #e5ebf3; background:#fff; border-radius:10px; padding:9px 0; font-weight:700; font-size:13px; cursor:pointer; }
  .payroll-month-picker .mp-month:hover { background:#f1f6ff; border-color:#1677f9; }
  .payroll-month-picker .mp-month.active { background:#1677f9; border-color:#1677f9; color:#fff; }
  .payroll-month-picker .mp-month.has-data::after { content:''; position:absolute; top:5px; left:6px; width:6px; height:6px; border-radius:50%; background:#16a36f; }
  .payroll-month-picker .mp-note { font-size:11px; color:#64748b; margin-top:8px; text-align:center; }
  #period { cursor:pointer; }
</style>

<ul class="nav nav-tabs payroll-view-tabs" id="payrollViewTabs" role="tablist">
  <li class="nav-item" role="presentation"><button type="button" class="nav-link active" data-payroll-tab="current"><i class="bi bi-wallet2"></i> {{ __('مسير') }} {{ $arabicMonthLabel ?? $month->format('m/Y') }}</button></li>
  <li class="nav-item" role="presentation"><button type="button" class="nav-link" data-payroll-tab="history"><i class="bi bi-clock-history"></i> {{ __('المسيرات السابقة') }} <span class="badge text-bg-secondary">{{ $payrollHistory->count() }}</span></button></li>
</ul>

<div class="employee-payroll-actions d-flex gap-2 flex-wrap mb-3">
  @if(auth()->user()?->hasPermission('payroll.export'))
    <a class="btn btn-success" href="{{ route('payroll.export', ['month' => $month->format('Y-m-01'), 'format' => 'csv']) }}"><i class="bi bi-file-earmark-excel"></i> {{ __('تصدير Excel') }}</a>
  @endif
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> {{ __('طباعة') }}</button>
  @if(auth()->user()?->hasPermission('payroll.create'))
    <form method="POST" action="{{ route('payroll.runs.generate') }}" class="d-inline">
      @csrf
      <input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}">
      <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> {{ __('تحديث المسير') }}</button>
    </form>
  @endif
  @if(!empty($lastPayrollRefreshAt))
    <span class="text-muted small align-self-center" title="{{ $lastPayrollRefreshAt->format('d/m/Y H:i') }}"><i class="bi bi-clock-history"></i> {{ __('آخر تحديث:') }} {{ $lastPayrollRefreshAt->isToday() ? $lastPayrollRefreshAt->format('H:i') : $lastPayrollRefreshAt->format('d/m/Y') }}</span>
  @endif
</div>

<div id="payrollPrototype" class="payroll-prototype-page">{!! $pageBody !!}</div>

<div id="payrollHistoryPane" hidden>
  @if($payrollHistory->isEmpty())
    <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>{{ __('لا توجد مسيرات سابقة بعد.') }}</div>
  @else
    <p class="text-muted small mb-3">{{ __('اضغط على أي شهر لعرض مسير الرواتب الخاص به.') }}</p>
    <div class="payroll-history-grid">
      @foreach($payrollHistory as $item)
        <button type="button" class="payroll-history-card {{ $item['current'] ? 'is-current' : '' }}" data-history-month="{{ $item['month'] }}">
          <h4><span>{{ $item['label'] }}</span><span class="payroll-status s-{{ $item['status'] }}">{{ $item['status_label'] }}</span></h4>
          <div class="row-line"><span>{{ __('عدد الموظفين') }}</span><b>{{ number_format($item['employees']) }}</b></div>
          <div class="row-line"><span>{{ __('صافي الرواتب') }}</span><b class="net">{{ number_format($item['net'], 2) }}</b></div>
          <div class="row-line"><span>{{ __('تكلفة الشركة') }}</span><b>{{ number_format($item['cost'], 2) }}</b></div>
        </button>
      @endforeach
    </div>
  @endif
</div>
<script>
window.masarEmployeeSalaries = {
  people: {!! $peopleJson !!},
  stats: @json($prototypeStats),
  printUrl: @json(route('payroll.export', ['month' => $month->format('Y-m-01')])),
  monthIndex: {{ $monthIndex }},
  year: {{ $month->format('Y') }},
  month: @json($month->format('Y-m-01')),
  history: @json($payrollHistory),
  isCurrentMonth: @json($month->format('Y-m') === now()->format('Y-m')),
  pdfUrlTemplate: @json(route('payroll.employee-salaries.payslip-pdf', ['payrollRecord' => '__RECORD__']))
};
</script>
<script src="{{ asset('payroll-assets/employee-salaries.js') }}?v={{ @filemtime(public_path('payroll-assets/employee-salaries.js')) }}"></script>
@endsection
