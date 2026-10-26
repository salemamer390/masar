@php
    // $scale is only used by the controller's fallback attempts (1.0 = normal size).
    $scale = $scale ?? 1.0;
    $fs = fn (float $pt) => round($pt * $scale, 1) . 'pt';
    $pad = fn (float $mm) => round($mm * $scale, 1) . 'mm';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
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
    <td class="brand-cell" width="65%"><div class="title">MASAR HR</div><div class="sub">&#x642;&#x633;&#x64a;&#x645;&#x629; &#x631;&#x627;&#x62a;&#x628;</div></td>
    <td class="brand-cell period" width="35%">{{ $record->payroll_month?->translatedFormat('F Y') }}</td>
  </tr>
</table>

<table class="emp">
  <tr>
    <td width="34%"><div class="label">&#x627;&#x633;&#x645; &#x627;&#x644;&#x645;&#x648;&#x638;&#x641;</div><div class="value">{{ $record->employee?->full_name ?? '—' }}</div></td>
    <td width="22%"><div class="label">&#x627;&#x644;&#x643;&#x648;&#x62f; &#x627;&#x644;&#x648;&#x638;&#x64a;&#x641;&#x64a;</div><div class="value">{{ $record->employee?->employee_code ?? '—' }}</div></td>
    <td width="44%"><div class="label">&#x627;&#x644;&#x642;&#x633;&#x645; / &#x627;&#x644;&#x648;&#x638;&#x64a;&#x641;&#x629;</div><div class="value">{{ collect([$record->employee?->department?->name, $record->employee?->job?->title])->filter()->implode(' — ') ?: '—' }}</div></td>
  </tr>
</table>

<div class="gap"></div>

<table><tr><td class="h-earn">&#x627;&#x644;&#x627;&#x633;&#x62a;&#x62d;&#x642;&#x627;&#x642;&#x627;&#x62a;</td></tr></table>
<table class="line">
  @foreach($earnings as [$label, $amount])
    <tr><td width="68%">{{ $label }}</td><td width="32%" class="amt">{{ number_format($amount, 2) }} EGP</td></tr>
  @endforeach
</table>
<table class="tot-earn"><tr><td width="68%">&#x625;&#x62c;&#x645;&#x627;&#x644;&#x64a; &#x627;&#x644;&#x627;&#x633;&#x62a;&#x62d;&#x642;&#x627;&#x627;&#x62a;</td><td width="32%" class="amt">{{ number_format($earningsTotal, 2) }} EGP</td></tr></table>

<div class="gap"></div>

<table><tr><td class="h-ded">&#x627;&#x633;&#x62a;&#x642;&#x637;&#x627;&#x639;&#x627;&#x62a;</td></tr></table>
<table class="line">
  @forelse($deductions as [$label, $amount])
    <tr><td width="68%">{{ $label }}</td><td width="32%" class="amt">{{ number_format($amount, 2) }} EGP</td></tr>
  @empty
    <tr><td colspan="2" style="text-align:center;color:#71809a;">&#x644;&#x627; &#x62a;&#x648;&#x62c;&#x62f; &#x627;&#x633;&#x62a;&#x642;&#x637;&#x627;&#x639;&#x627;&#x62a;</td></tr>
  @endforelse
</table>
<table class="tot-ded"><tr><td width="68%">&#x625;&#x62c;&#x645;&#x627;&#x644;&#x64a; &#x627;&#x633;&#x62a;&#x642;&#x637;&#x627;&#x639;&#x627;&#x62a;</td><td width="32%" class="amt">{{ number_format($deductionsTotal, 2) }} EGP</td></tr></table>

<div class="gap"></div>

<table class="net"><tr><td width="55%">&#x635;&#x627;&#x641;&#x64a; &#x627;&#x644;&#x631;&#x627;&#x62a;&#x628;</td><td width="45%" class="amt">{{ number_format($net, 2) }} EGP</td></tr></table>

<div class="gap"></div>
<div class="foot">&#x62a;&#x645; &#x625;&#x646;&#x634;&#x627;&#x621; &#x627;&#x644;&#x642;&#x633;&#x64a;&#x645;&#x629; &#x628;&#x648;&#x627;&#x633;&#x637;&#x629; MASAR HR</div>

</body>
</html>
