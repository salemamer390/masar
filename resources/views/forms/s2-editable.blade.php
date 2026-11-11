<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('نموذج س2') }}</title>
    <style>
        @font-face { font-family: 'Mudir MT'; src: url('{{ asset('fonts/MudirMT.ttf') }}') format('truetype'); }
        @font-face { font-family: 'PT Bold Heading'; src: url('{{ asset('fonts/PTBoldHeading.ttf') }}') format('truetype'); }
        @page { size: A4 landscape; margin: 0; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { margin: 0; padding: 0; background: #ddd; color: #000; direction: rtl; }
        body { font-family: Tahoma, Arial, sans-serif; font-size: 12pt; unicode-bidi: plaintext; }
        .tools { width: 297mm; margin: 12px auto; display: flex; gap: 8px; }
        .tools button, .tools a { padding: 9px 16px; border: 0; border-radius: 5px; background: #145ec8; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
        .tools a { background: #fff; color: #19345b; border: 1px solid #c8d2df; }
        .page { width: 297mm; height: 210mm; margin: 0 auto 12px; padding: 8mm 10mm; background: #fff; overflow: hidden; page-break-after: always; direction: rtl; }
        .page:last-child { page-break-after: auto; }
        @media print { body { background: #fff; } .tools { display: none !important; } .page { margin: 0; } }
        .topline { display: grid; direction: ltr; grid-template-columns: 1fr 24mm 1fr; align-items: center; min-height: 18mm; border-bottom: 1.2mm double #111; padding-bottom: 2mm; }
        .head { display: grid; grid-template-columns: 1fr 140pt 1fr; align-items: center; border-bottom: 2pt solid #000; padding-bottom: 5pt; direction: rtl; }
        .head { direction: ltr; grid-template-areas: 'left logo right'; }
        .head .right { grid-area: right; font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 15pt; line-height: 1.7; text-align: right; direction: rtl; }
        .head .left { grid-area: left; text-align: left; font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 13pt; direction: rtl; }
        .head img { grid-area: logo; height: 58pt; display: block; margin: auto; }
        .line { display: inline-block; min-width: 185pt; height: 18pt; border-bottom: 1px dotted #000; vertical-align: bottom; }
        .brand { font-weight: bold; font-size: 11pt; line-height: 1.6; }
        .brand.left { text-align: right; direction: rtl; }
        .logo-mark { height: 18mm; width: 18mm; border: 1px solid #aaa; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 6pt; font-weight: bold; color: #275b82; margin: auto; direction: rtl; }
        .formno { display: flex; align-items: center; gap: 2mm; font-size: 10pt; font-weight: bold; white-space: nowrap; }
        .header-left { display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 1mm; direction: rtl; }
        .header-establishment { display: flex; align-items: center; gap: 1.5mm; font-size: 9pt; white-space: nowrap; }
        .header-establishment .lab { font-weight: bold; }
        .line { display: inline-block; min-width: 185pt; height: 18pt; border-bottom: 1px dotted #000; vertical-align: bottom; }
        .titleline { display: flex; direction: ltr; align-items: center; justify-content: center; gap: 5mm; margin: 2.5mm 0 2mm; font-weight: bold; font-size: 13pt; }
        .titleline .year { font-size: 10pt; white-space: nowrap; }
        .section-title { display: flex; align-items: center; gap: 0; margin: 1.5mm 0; }
        .section-title::before, .section-title::after { content: ''; height: 0; border-top: .7mm solid #111; flex: 1; }
        .section-title span { border: .35mm solid #222; border-radius: 3mm; padding: 1.5mm 10mm; font-weight: bold; font-size: 10pt; white-space: nowrap; min-width: 70mm; text-align: center; background: #fff; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2mm 6mm; margin: 1mm 3mm 2mm; direction: rtl; }
        .meta-field { display: flex; align-items: flex-end; gap: 1.5mm; min-width: 0; min-height: 7mm; font-size: 9pt; white-space: nowrap; }
        .meta-field .lab { font-weight: 700; flex: none; }
        .meta-field .fill { border-bottom: .25mm dotted #111; min-width: 0; flex: 1; min-height: 5mm; padding: 0 1mm; text-align: center; overflow: hidden; font-size: 11pt; font-weight: 500; line-height: 1.15; }
        .meta-field.phone-row { grid-column: 3; grid-row: 2; }
        .meta-field.commercial-row { grid-column: 1 / -1; grid-row: 4; gap: 1.5mm; }
        .meta-field.commercial-row .lab, .meta-field.establishment-row .lab { font-size: 8.5pt; }
        .meta-field.commercial-row .fill, .meta-field.establishment-row .fill { flex: 1; min-width: 12mm; }
        .meta-field.establishment-row { grid-column: 1 / -1; grid-row: 3; gap: 1.5mm; }
        .boxes { display: inline-flex; direction: ltr; vertical-align: middle; }
        .boxes i { display: flex; align-items: center; justify-content: center; width: 21pt; height: 21pt; border: 1px solid #000; margin-left: -1px; padding: 0; font-family: Tahoma, Arial, sans-serif; font-size: 10pt; font-style: normal; line-height: 1; text-align: center; direction: ltr; unicode-bidi: isolate; }
        .table-grid-cell { padding: 0; height: 20pt; }
        .table-grid { display: grid; height: 100%; width: 100%; direction: ltr; }
        .table-grid.grid-9 { grid-template-columns: repeat(9, 1fr); }
        .table-grid.grid-14 { grid-template-columns: repeat(14, 1fr); }
        .table-grid span { display: block; min-width: 0; height: 100%; }
        .table-grid span + span { border-left: 1px solid #000; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8pt; font-size: 11pt; direction: rtl; }
        th, td { border: 1px solid #000; text-align: center; height: 20pt; }
        th { font-family: Tahoma, Arial, sans-serif; font-size: 12pt; }
        .name { width: 17.5%; } .nid { width: 24.2%; } .ins { width: 22.1%; } .date { width: 5.1%; } .wage { width: 10%; }
        .employee-table th:nth-child(3), .employee-table td:nth-child(3) { width: 6.1%; }
        .employee-table th:nth-child(4), .employee-table td:nth-child(4) { width: 5.1%; }
        .employee-table th:nth-child(5), .employee-table td:nth-child(5) { width: 4.5%; }
        .declaration-title { font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 17pt; text-align: center; margin-top: 2mm; }
        .declaration { font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 14pt; line-height: 2.35; margin-top: 12pt; text-align: justify; direction: rtl; }
        .signature { margin-top: 18pt; font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 14pt; }
        .signature-date { display: block; margin-top: 18pt; }
        .signature-date .signature { margin-top: 0; text-align: left; direction: rtl; }
        .date-line { margin-top: 14pt; text-align: right; direction: rtl; font-size: 14pt; font-weight: 700; }
    </style>
</head>
<body>
    <div class="tools"><button type="button" onclick="window.print()">{{ __('طباعة س2') }}</button><a href="{{ route('insurance.index') }}">{{ __('العودة للتأمينات') }}</a></div>
    <section class="page">
        <div class="head">
            <div class="right">{{ __('الهيئة القومية للتأمين الاجتماعي') }}<br>{{ __('مكتب:') }} <span class="line">{{ $d['office'] }}</span></div>
            <img src="{{ asset('images/nosi-logo.jpg') }}" alt="">
            <div class="left">{{ __('نموذج رقم (٢)') }}<br><br>{{ __('رقم المنشأة:') }} <span class="boxes">@for($i=0;$i<9;$i++)<i>{{ mb_substr($d['establishment_number'], $i, 1) }}</i>@endfor</span></div>
        </div>
        <div class="titleline"><span class="year">{{ __('٢٠٢٦') }}</span><span>/</span><span>/</span><span>{{ __('إخطار بتعديل / تحديث بيانات أجور المؤمن عليهم في') }}</span></div>
        <div class="section-title"><span>{{ __('بيانات مقدم الطلب / المنشأة') }}</span></div>
        <div class="meta-grid">
            <div class="meta-field"><span class="lab">{{ __('مقدم الطلب:') }}</span><span class="fill">{{ $d['applicant_name'] }}</span></div><div class="meta-field"><span class="lab">{{ __('صفة مقدم الطلب:') }}</span><span class="fill">{{ $d['applicant_title'] }}</span></div><div class="meta-field"><span class="lab">{{ __('رقم تأميني:') }}</span><span class="boxes">@for($i=0;$i<9;$i++)<i>{{ mb_substr($d['applicant_insurance_number'], $i, 1) }}</i>@endfor</span></div>
            <div class="meta-field"><span class="lab">{{ __('رقم قومي:') }}</span><span class="boxes">@for($i=0;$i<14;$i++)<i>{{ mb_substr($d['applicant_national_id'], $i, 1) }}</i>@endfor</span></div><div class="meta-field phone-row"><span class="lab">{{ __('رقم التليفون:') }}</span><span class="fill">{{ $d['phone'] }}</span></div>
            <div class="meta-field establishment-row"><span class="lab">{{ __('اسم المنشأة:') }}</span><span class="fill">{{ $d['organization_name'] }}</span><span class="lab">{{ __('قطاع المنشأة:') }}</span><span class="fill">{{ $d['sector'] }}</span><span class="lab">{{ __('رقم التسجيل الضريبي:') }}</span><span class="fill">{{ $d['tax_registration_number'] }}</span></div>
            <div class="meta-field commercial-row"><span class="lab">{{ __('رقم السجل التجاري:') }}</span><span class="fill">{{ $d['commercial_registration_number'] }}</span><span class="lab">{{ __('رقم السجل التجاري الموحد:') }}</span><span class="fill">{{ $d['unified_commercial_registration_number'] }}</span></div>
        </div>
        <div class="section-title"><span>{{ __('بيانات المؤمن عليهم') }}</span></div>
        <table>
            <colgroup><col style="width:22.1%"><col style="width:17.5%"><col style="width:24.2%"><col style="width:4.5%"><col style="width:5.1%"><col style="width:6.1%"><col style="width:10.5%"><col style="width:10%"></colgroup>
            <thead><tr><th>{{ __('الرقم التأميني') }}</th><th>{{ __('اسم المؤمن عليه') }}</th><th>{{ __('الرقم القومي') }}</th><th colspan="3">{{ __('تاريخ الالتحاق') }}</th><th>{{ __('أجر الاشتراك') }}</th><th>{{ __('الأجر الشامل') }}</th></tr><tr><th></th><th></th><th></th><th>{{ __('يوم') }}</th><th>{{ __('شهر') }}</th><th>{{ __('سنة') }}</th><th>{{ __('جنيه') }}</th><th>{{ __('جنيه') }}</th></tr></thead>
            <tbody>@for($row = 0; $row < 10; $row++)<tr><td class="table-grid-cell"><div class="table-grid grid-9">@for($i=0;$i<9;$i++)<span></span>@endfor</div></td><td></td><td class="table-grid-cell"><div class="table-grid grid-14">@for($i=0;$i<14;$i++)<span></span>@endfor</div></td><td></td><td></td><td></td><td></td><td></td></tr>@endfor</tbody>
        </table>
    </section>
    <section class="page">
        <div class="declaration-title">{{ __('إقرار') }}</div>
        <div class="declaration">{{ __('أقر أنا .......................................................... بصفتي .......................................................... بأن جميع البيانات الواردة بهذا النموذج وملحقاته صحيحة (الأجر الشامل لحساب اشتراكات التأمين الصحي الشامل)، وأني أتحمل المسؤولية القانونية عن صحة البيانات الواردة بهذه الاستمارة، وفي حالة المخالفة تطبق العقوبات المنصوص عليها بالقانون رقم ١٤٨ لسنة ٢٠١٩ مع عدم الإخلال بأي عقوبات أشد واردة في قانون العقوبات، وعلى أن يتم موافاة الهيئة بأي تعديلات تطرأ على أجور العاملين بالمنشأة في أي تاريخ خلال العام يصدر فيه قرار أو قانون بتعديل الأجور وكذلك في يناير من كل عام.') }}</div>
        <div class="signature-date">
            <div class="signature">{{ __('توقيع صاحب العمل أو المدير المسؤول') }}<br>..........................................................</div>
            <div class="date-line">{{ __('تحريرًا في: &nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp; ٢٠') }}</div>
        </div>
    </section>
</body>
</html>
