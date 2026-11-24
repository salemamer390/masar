@php
    // $scale is only used by the controller's fallback attempts (1.0 = normal size).
    $scale = $scale ?? 1.0;
    $fs = fn (float $pt) => round($pt * $scale, 1) . 'pt';
    $pad = fn (float $mm) => round($mm * $scale, 1) . 'mm';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>Payslip</title>
{{-- mPDF-safe CSS on purpose: tables with explicit column widths, no flex/grid, no
     page-break-inside, no white-space:nowrap, no direction overrides, no @page. --}}
<style>
body { font-family: dejavusans; color: #24344d; font-size: {{ $fs(11) }}; }
table { border-collapse: collapse; width: 100%; }
td { vertical-align: top; }
.co-name { font-size: {{ $fs(19) }}; font-weight: bold; color: #10213d; }
.co-sub { font-size: {{ $fs(9) }}; color: #71809a; padding-top: {{ $pad(1.5) }}; }
.doc-title { font-size: {{ $fs(15) }}; font-weight: bold; color: #1260d8; text-align: left; }
.doc-meta { font-size: {{ $fs(9) }}; color: #71809a; text-align: left; padding-top: {{ $pad(1.5) }}; }
.hdr td { padding-bottom: {{ $pad(3) }}; border-bottom: 0.9mm solid #10213d; }
.emp { margin-top: {{ $pad(4) }}; }
.emp td { padding: {{ $pad(3) }} {{ $pad(2.5) }}; border: 0.2mm solid #dfe7f1; background-color: #f7fafd; }
.label { font-size: {{ $fs(8) }}; color: #71809a; }
.value { font-weight: bold; font-size: {{ $fs(10.5) }}; }
.gap { height: {{ $pad(4) }}; }
.sec { padding: {{ $pad(2.6) }} {{ $pad(2.5) }}; font-size: {{ $fs(11.5) }}; font-weight: bold; }
.sec-earn { color: #087f5b; background-color: #e9f8f1; border: 0.2mm solid #c9ecdd; }
.sec-ded { color: #c92a3f; background-color: #fff0f2; border: 0.2mm solid #f3d2d8; }
.cols td.col { width: 50%; vertical-align: top; }
.line td { padding: {{ $pad(2.7) }} {{ $pad(2.5) }}; border-bottom: 0.2mm solid #edf1f7; font-size: {{ $fs(10) }}; }
.amt { text-align: left; font-weight: bold; }
.tot td { padding: {{ $pad(2.8) }} {{ $pad(2.5) }}; font-weight: bold; font-size: {{ $fs(10.5) }}; }
.tot-earn td { color: #087f5b; background-color: #e9f8f1; border-top: 0.3mm solid #c9ecdd; }
.tot-ded td { color: #c92a3f; background-color: #fff0f2; border-top: 0.3mm solid #f3d2d8; }
.colgap { width: 4mm; }
.net td { padding: {{ $pad(4.5) }} {{ $pad(3) }}; background-color: #10213d; color: #ffffff; font-weight: bold; font-size: {{ $fs(13) }}; }
.net .amt { color: #ffffff; font-size: {{ $fs(16) }}; }
.foot { font-size: {{ $fs(7.5) }}; color: #8290a4; text-align: center; padding-top: {{ $pad(3) }}; border-top: 0.2mm solid #dfe7f1; }
</style>
</head>
<body>

<table class="hdr">
  <tr>
    <td width="60%">
      <div class="co-name">{{ $record->employee?->company?->name ?? config('app.name') }}</div>
      <div class="co-sub">{{ __('إدارة الموارد البشرية — قسم الرواتب') }}</div>
    </td>
    <td width="40%">
      <div class="doc-title">{{ __('قسيمة راتب') }}</div>
      <div class="doc-meta">{{ $record->payroll_month?->translatedFormat('F Y') }} &nbsp;·&nbsp; {{ __('قسيمة رقم') }} {{ $record->id }}</div>
    </td>
  </tr>
</table>

<table class="emp">
  <tr>
    <td width="40%"><div class="label">{{ __('اسم الموظف') }}</div><div class="value">{{ $record->employee?->full_name ?? '—' }}</div></td>
    <td width="22%"><div class="label">{{ __('الكود الوظيفي') }}</div><div class="value">{{ $record->employee?->employee_code ?? '—' }}</div></td>
    <td width="38%"><div class="label">{{ __('القسم / الوظيفة') }}</div><div class="value">{{ collect([$record->employee?->department?->name, $record->employee?->job?->title])->filter()->implode(' — ') ?: '—' }}</div></td>
  </tr>
</table>

<div class="gap"></div>

<table class="cols">
  <tr>
    <td class="col">
      <table>
        <tr><td class="sec sec-earn">{{ __('الاستحقاقات') }}</td></tr>
      </table>
      <table class="line">
        @foreach($earnings as [$label, $amount])
          <tr><td width="62%">{{ $label }}</td><td width="38%" class="amt">{{ number_format($amount, 2) }}</td></tr>
        @endforeach
      </table>
      <table class="tot tot-earn"><tr><td width="62%">{{ __('الإجمالي') }}</td><td width="38%" class="amt">{{ number_format($earningsTotal, 2) }}</td></tr></table>
    </td>
    <td class="colgap"></td>
    <td class="col">
      <table>
        <tr><td class="sec sec-ded">{{ __('الاستقطاعات') }}</td></tr>
      </table>
      <table class="line">
        @forelse($deductions as [$label, $amount])
          <tr><td width="62%">{{ $label }}</td><td width="38%" class="amt">{{ number_format($amount, 2) }}</td></tr>
        @empty
          <tr><td style="text-align:center;color:#71809a;">{{ __('لا توجد استقطاعات') }}</td></tr>
        @endforelse
      </table>
      <table class="tot tot-ded"><tr><td width="62%">{{ __('الإجمالي') }}</td><td width="38%" class="amt">{{ number_format($deductionsTotal, 2) }}</td></tr></table>
    </td>
  </tr>
</table>

<div class="gap"></div>

<table class="net"><tr><td width="55%">{{ __('صافي الراتب المستحق') }}</td><td width="45%" class="amt">{{ number_format($net, 2) }} {{ __('ج.م') }}</td></tr></table>

<div class="gap"></div>
<div class="foot">{{ __('أُصدرت هذه القسيمة إلكترونيًا بواسطة نظام ODIR HR في') }} {{ now()->format('d/m/Y') }}</div>

</body>
</html>
