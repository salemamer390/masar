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
body { font-family: dejavusans; color: #24344d; font-size: {{ $fs(11.5) }}; }
table { border-collapse: collapse; width: 100%; }
td { vertical-align: top; }
.brand-cell { padding-bottom: {{ $pad(2) }}; border-bottom: 0.6mm solid #1769e0; }
.title { font-size: {{ $fs(20) }}; font-weight: bold; color: #1769e0; }
.sub { font-size: {{ $fs(10) }}; color: #71809a; }
.period { font-size: {{ $fs(13) }}; font-weight: bold; text-align: left; }
.emp td { padding: {{ $pad(3.5) }} {{ $pad(2.5) }}; border-bottom: 0.2mm solid #dfe7f1; }
.label { font-size: {{ $fs(9) }}; color: #71809a; }
.value { font-weight: bold; }
.h-earn { font-size: {{ $fs(14) }}; font-weight: bold; color: #087f5b; background-color: #e9f8f1; padding: {{ $pad(2.8) }} {{ $pad(2.5) }}; }
.h-ded { font-size: {{ $fs(14) }}; font-weight: bold; color: #d63345; background-color: #fff0f2; padding: {{ $pad(2.8) }} {{ $pad(2.5) }}; }
.line td { padding: {{ $pad(3.1) }} {{ $pad(2.5) }}; border-bottom: 0.2mm solid #edf1f7; }
.amt { text-align: left; font-weight: bold; }
.tot-earn td { padding: {{ $pad(3) }}; font-weight: bold; color: #087f5b; background-color: #e9f8f1; }
.tot-ded td { padding: {{ $pad(3) }}; font-weight: bold; color: #d63345; background-color: #fff0f2; }
.net td { padding: {{ $pad(5) }}; background-color: #eef5ff; border: 0.3mm solid #d7e7ff; font-weight: bold; }
.net .amt { color: #1769e0; font-size: {{ $fs(19) }}; }
.foot { font-size: {{ $fs(7.5) }}; color: #8290a4; text-align: center; }
.gap { height: {{ $pad(6) }}; }
</style>
</head>
<body>

<table>
  <tr>
    <td class="brand-cell" width="65%"><div class="title">MASAR HR</div><div class="sub">{{ __('قسيمة راتب') }}</div></td>
    <td class="brand-cell period" width="35%">{{ $record->payroll_month?->translatedFormat('F Y') }}</td>
  </tr>
</table>

<table class="emp">
  <tr>
    <td width="34%"><div class="label">{{ __('اسم الموظف') }}</div><div class="value">{{ $record->employee?->full_name ?? '—' }}</div></td>
    <td width="22%"><div class="label">{{ __('الكود الوظيفي') }}</div><div class="value">{{ $record->employee?->employee_code ?? '—' }}</div></td>
    <td width="44%"><div class="label">{{ __('القسم / الوظيفة') }}</div><div class="value">{{ collect([$record->employee?->department?->name, $record->employee?->job?->title])->filter()->implode(' — ') ?: '—' }}</div></td>
  </tr>
</table>

<div class="gap"></div>

<table><tr><td class="h-earn">{{ __('الاستحقاقات') }}</td></tr></table>
<table class="line">
  @foreach($earnings as [$label, $amount])
    <tr><td width="68%">{{ $label }}</td><td width="32%" class="amt">{{ number_format($amount, 2) }} EGP</td></tr>
  @endforeach
</table>
<table class="tot-earn"><tr><td width="68%">{{ __('إجمالي الاستحقاات') }}</td><td width="32%" class="amt">{{ number_format($earningsTotal, 2) }} EGP</td></tr></table>

<div class="gap"></div>

<table><tr><td class="h-ded">{{ __('استقطاعات') }}</td></tr></table>
<table class="line">
  @forelse($deductions as [$label, $amount])
    <tr><td width="68%">{{ $label }}</td><td width="32%" class="amt">{{ number_format($amount, 2) }} EGP</td></tr>
  @empty
    <tr><td colspan="2" style="text-align:center;color:#71809a;">{{ __('لا توجد استقطاعات') }}</td></tr>
  @endforelse
</table>
<table class="tot-ded"><tr><td width="68%">{{ __('إجمالي استقطاعات') }}</td><td width="32%" class="amt">{{ number_format($deductionsTotal, 2) }} EGP</td></tr></table>

<div class="gap"></div>

<table class="net"><tr><td width="55%">{{ __('صافي الراتب') }}</td><td width="45%" class="amt">{{ number_format($net, 2) }} EGP</td></tr></table>

<div class="gap"></div>
<div class="foot">{{ __('تم إنشاء القسيمة بواسطة MASAR HR') }}</div>

</body>
</html>
