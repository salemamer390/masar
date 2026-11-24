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
body { font-family: xbriyaz; color: #24344d; font-size: {{ $fs(12.5) }}; }
table { border-collapse: collapse; width: 100%; }
td { vertical-align: top; }
td.co-name { font-size: {{ $fs(22) }}; font-weight: bold; color: #10213d; padding: 0; }
td.co-sub { font-size: {{ $fs(12) }}; color: #71809a; padding: 0; }
.stackgap td { height: {{ $pad(1.4) }}; }
.hdr table td { border-bottom: none; padding: 0; }
.emp table td { border: none; background-color: transparent; padding: 0; }
td.doc-title { font-size: {{ $fs(17) }}; font-weight: bold; color: #1260d8; text-align: left; padding: 0; }
td.doc-meta { font-size: {{ $fs(12) }}; color: #71809a; text-align: left; padding: 0; }
.hdr td { padding-bottom: {{ $pad(3) }}; border-bottom: 0.9mm solid #10213d; }
.emp { margin-top: {{ $pad(4) }}; }
.emp td { padding: {{ $pad(3) }} {{ $pad(2.5) }}; border: 0.2mm solid #dfe7f1; background-color: #f7fafd; }
.label { font-size: {{ $fs(9.5) }}; color: #71809a; padding: 0; }
.value { font-weight: bold; font-size: {{ $fs(12) }}; }
.gap { height: {{ $pad(4) }}; }
.sec { padding: {{ $pad(2.6) }} {{ $pad(2.5) }}; font-size: {{ $fs(13.5) }}; font-weight: bold; }
.sec-earn { color: #087f5b; background-color: #e9f8f1; border: 0.2mm solid #c9ecdd; }
.sec-ded { color: #c92a3f; background-color: #fff0f2; border: 0.2mm solid #f3d2d8; }
.cols td.col { width: 50%; vertical-align: top; }
.line td { padding: {{ $pad(3) }} {{ $pad(2.5) }}; border-bottom: 0.2mm solid #edf1f7; font-size: {{ $fs(11.5) }}; }
.amt { text-align: left; font-weight: bold; }
.tot td { padding: {{ $pad(2.8) }} {{ $pad(2.5) }}; font-weight: bold; font-size: {{ $fs(12) }}; }
.tot-earn td { color: #087f5b; background-color: #e9f8f1; border-top: 0.3mm solid #c9ecdd; }
.tot-ded td { color: #c92a3f; background-color: #fff0f2; border-top: 0.3mm solid #f3d2d8; }
.colgap { width: 4mm; }
.net td { padding: {{ $pad(4.5) }} {{ $pad(3) }}; background-color: #10213d; color: #ffffff; font-weight: bold; font-size: {{ $fs(15) }}; }
.net .amt { color: #ffffff; font-size: {{ $fs(18) }}; }
.foot { font-size: {{ $fs(9) }}; color: #8290a4; text-align: center; padding-top: {{ $pad(3) }}; border-top: 0.2mm solid #dfe7f1; }
</style>
</head>
<body>

<table class="hdr">
  <tr>
    <td width="60%">
      <table>
        <tr><td class="co-name">{{ $record->employee?->company?->name ?? config('app.name') }}</td></tr>
        <tr class="stackgap"><td></td></tr>
        <tr><td class="co-sub">{{ __('إدارة الموارد البشرية — قسم الرواتب') }}</td></tr>
      </table>
    </td>
    <td width="40%">
      <table>
        <tr><td class="doc-title">{{ __('قسيمة راتب') }}</td></tr>
        <tr class="stackgap"><td></td></tr>
        <tr><td class="doc-meta">{{ $record->payroll_month?->translatedFormat('F Y') }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="emp">
  <tr>
    <td width="40%"><table><tr><td class="label">{{ __('اسم الموظف') }}</td></tr><tr class="stackgap"><td></td></tr><tr><td class="value">{{ $record->employee?->full_name ?? '—' }}</td></tr></table></td>
    <td width="22%"><table><tr><td class="label">{{ __('الكود الوظيفي') }}</td></tr><tr class="stackgap"><td></td></tr><tr><td class="value">{{ ltrim((string) ($record->employee?->employee_code ?? ''), '#') ?: '—' }}</td></tr></table></td>
    <td width="38%"><table><tr><td class="label">{{ __('القسم / الوظيفة') }}</td></tr><tr class="stackgap"><td></td></tr><tr><td class="value">{{ collect([$record->employee?->department?->name, $record->employee?->job?->title])->filter()->implode(' — ') ?: '—' }}</td></tr></table></td>
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
