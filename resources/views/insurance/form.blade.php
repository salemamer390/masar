<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('نموذج س1 -') }} {{ $insuranceRecord->employee?->full_name }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9edf2; font-family: Tahoma, Arial, sans-serif; color: #111; }
        .toolbar { width: 210mm; margin: 12px auto; display: flex; gap: 8px; justify-content: flex-start; }
        .toolbar button, .toolbar a { border: 0; border-radius: 5px; padding: 9px 15px; background: #145ec8; color: #fff; text-decoration: none; font: inherit; cursor: pointer; }
        .toolbar a { background: #fff; color: #19345b; border: 1px solid #c8d2df; }
        .sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto 12px; background: #fff center/100% 100% no-repeat; overflow: hidden; }
        .front { background-image: url('{{ asset('templates/insurance/s1-page1.png') }}'); }
        .back { background-image: url('{{ asset('templates/insurance/s1-page2.png') }}'); }
        .field { position: absolute; z-index: 2; white-space: nowrap; font-size: 3.2mm; font-weight: 700; line-height: 1; direction: rtl; }
        .small { font-size: 2.8mm; }
        .center { transform: translateX(-50%); text-align: center; }
        .ltr { direction: ltr; }
        .check { font-size: 5mm; font-weight: 900; }
        .digits { display: flex; direction: ltr; gap: 0; height: 6.5mm; }
        .digit { width: 6.35mm; height: 6.5mm; display: inline-flex; align-items: center; justify-content: center; font-size: 3.4mm; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; page-break-after: always; } .sheet:last-child { page-break-after: auto; } }
        @media screen and (max-width: 850px) { .toolbar, .sheet { width: 100vw; } .sheet { height: 141.43vw; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">{{ __('طباعة نموذج س1') }}</button><a href="{{ route('insurance.index') }}">{{ __('العودة للتأمينات') }}</a></div>

    @php
        $employee = $insuranceRecord->employee;
        $nationalId = preg_replace('/\D+/', '', (string) ($employee?->national_id ?? ''));
        $nationalId = str_pad(substr($nationalId, 0, 14), 14, ' ');
        $insuranceNumber = preg_replace('/\D+/', '', (string) ($insuranceRecord->insurance_number ?? ''));
        $insuranceNumber = str_pad(substr($insuranceNumber, 0, 9), 9, ' ');
        $establishmentNumber = preg_replace('/\D+/', '', (string) ($organization?->establishment_number ?? ''));
        $establishmentNumber = str_pad(substr($establishmentNumber, 0, 9), 9, ' ');
        $start = $insuranceRecord->insurance_start_date?->format('d/m/Y');
        $arabicDigits = fn ($value) => strtr((string) $value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    @endphp

    <section class="sheet front">
        {{-- مربعات الفئة --}}
        @if(($insuranceRecord->insurance_category ?? '') === 'العاملون لدى الغير')<span class="field check" style="top:35.7mm;right:45.7mm">✓</span>@endif
        @if(($insuranceRecord->insurance_category ?? '') !== 'العاملون لدى الغير')<span class="field check" style="top:35.7mm;right:83.8mm">✓</span>@endif
        <span class="field" style="top:48.2mm;right:42mm">{{ $organization?->applicant_name ?: '' }}</span>
        <span class="field" style="top:54.5mm;right:78mm">{{ $organization?->applicant_title ?: '' }}</span>
        <span class="field ltr" style="top:61.1mm;right:77mm">{{ $organization?->phone ?: '' }}</span>
        <span class="field digits" style="top:53.5mm;right:43mm">@foreach(str_split($insuranceNumber) as $d)<span class="digit">{{ trim($d) !== '' ? $arabicDigits($d) : '' }}</span>@endforeach</span>
        <span class="field digits" style="top:63.7mm;right:43mm">@foreach(str_split($nationalId) as $d)<span class="digit">{{ trim($d) !== '' ? $arabicDigits($d) : '' }}</span>@endforeach</span>

        <span class="field" style="top:76.7mm;right:73mm">{{ $employee?->full_name ?: '' }}</span>
        <span class="field digits" style="top:77.5mm;right:55mm">@foreach(str_split($insuranceNumber) as $d)<span class="digit">{{ trim($d) !== '' ? $arabicDigits($d) : '' }}</span>@endforeach</span>
        <span class="field digits" style="top:87.8mm;right:55mm">@foreach(str_split($nationalId) as $d)<span class="digit">{{ trim($d) !== '' ? $arabicDigits($d) : '' }}</span>@endforeach</span>
        <span class="field" style="top:98.2mm;right:81mm">{{ $employee?->gender === 'female' ? 'أنثى' : 'ذكر' }}</span>
        <span class="field" style="top:98.2mm;right:52mm">{{ $employee?->job?->title ?: '' }}</span>
        <span class="field ltr" style="top:108.7mm;right:39mm">{{ $start ? $arabicDigits($start) : '' }}</span>
        <span class="field" style="top:119.5mm;right:60mm">{{ $insuranceRecord->insurance_category ?: 'العاملون لدى الغير' }}</span>
        <span class="field ltr" style="top:136.3mm;right:119mm">{{ $insuranceRecord->insured_salary !== null ? $arabicDigits(number_format((float) $insuranceRecord->insured_salary, 2)) : '' }}</span>
        <span class="field ltr" style="top:136.3mm;right:61mm">{{ $insuranceRecord->insured_salary !== null ? $arabicDigits(number_format((float) $insuranceRecord->insured_salary, 2)) : '' }}</span>
        <span class="field" style="top:184.7mm;right:79mm">{{ $organization?->organization_name ?: '' }}</span>
        <span class="field digits" style="top:193.2mm;right:135mm">@foreach(str_split($establishmentNumber) as $d)<span class="digit">{{ trim($d) !== '' ? $arabicDigits($d) : '' }}</span>@endforeach</span>
        <span class="field" style="top:214.4mm;right:72mm">{{ $organization?->address ?: '' }}</span>
        <span class="field" style="top:234.5mm;right:45mm">{{ $organization?->applicant_name ?: '' }}</span>
        <span class="field ltr" style="top:240mm;right:42mm">{{ $organization?->phone ?: '' }}</span>
    </section>

    <section class="sheet back">
        <span class="field" style="top:144.5mm;right:49mm">{{ $organization?->organization_name ?: '' }}</span>
        <span class="field" style="top:144.5mm;right:127mm">{{ $arabicDigits(trim($insuranceNumber)) }}</span>
        <span class="field" style="top:153mm;right:61mm">{{ $organization?->address ?: '' }}</span>
        <span class="field" style="top:202mm;right:52mm">{{ $organization?->applicant_name ?: '' }}</span>
        <span class="field" style="top:246mm;right:54mm">{{ $employee?->full_name ?: '' }}</span>
    </section>
</body>
</html>
