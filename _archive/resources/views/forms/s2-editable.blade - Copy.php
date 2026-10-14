<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>نموذج س2</title>
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
        .head { display: grid; grid-template-columns: 1fr 140pt 1fr; align-items: center; border-bottom: 2pt solid #000; padding-bottom: 5pt; direction: rtl; }
        .head { direction: ltr; grid-template-areas: 'left logo right'; }
        .head .right { grid-area: right; font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 15pt; line-height: 1.7; text-align: right; direction: rtl; }
        .head .left { grid-area: left; text-align: left; font-family: Tahoma, Arial, sans-serif; font-weight: 700; font-size: 13pt; direction: rtl; }
        .head img { grid-area: logo; height: 58pt; display: block; margin: auto; }
        .line { display: inline-block; min-width: 185pt; height: 18pt; border-bottom: 1px dotted #000; vertical-align: bottom; }
        .title { font-family: Tahoma, Arial, sans-serif; font-weight: 700; direction: rtl; unicode-bidi: plaintext; text-align: center; font-size: 16pt; margin: 10pt 0 8pt; }
        .section { display: flex; align-items: center; gap: 12pt; margin: 8pt 0 6pt; }
        .section::before, .section::after { content: ''; flex: 1; border-top: 2.5pt solid #000; }
        .section span { border: 1.5pt solid #000; border-radius: 9pt; min-width: 210pt; padding: 3pt 28pt; text-align: center; font-size: 14pt; }
        .fields { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2mm 6mm; margin: 1mm 3mm 2mm; direction: rtl; }
        .field { white-space: nowrap; min-height: 19pt; direction: rtl; text-align: right; }
        .field b { font-family: Tahoma, Arial, sans-serif; font-weight: 700; }
        .field .fill { display: inline-block; height: 18pt; min-width: 150pt; border-bottom: 1px dotted #000; vertical-align: bottom; direction: rtl; text-align: right; }
        .boxes { display: inline-flex; direction: ltr; vertical-align: middle; }
        .boxes i { display: block; width: 21pt; height: 21pt; border: 1px solid #000; margin-left: -1px; }
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
        .date-line { margin-top: 14pt; text-align: left; font-size: 14pt; }
    </style>
</head>
<body>
    <div class="tools"><button type="button" onclick="window.print()">طباعة س2</button><a href="{{ route('insurance.index') }}">العودة للتأمينات</a></div>
    <section class="page">
        <div class="head">
            <div class="right">الهيئة القومية للتأمين الاجتماعي<br>مكتب: <span class="line"></span></div>
            <img src="{{ asset('images/nosi-logo.jpg') }}" alt="">
            <div class="left">نموذج رقم (٢)<br><br>رقم المنشأة: <span class="boxes">@for($i=0;$i<9;$i++)<i></i>@endfor</span></div>
        </div>
        <div class="title">إخطار بتعديل / تحديث بيانات أجور المؤمن عليهم في&nbsp;&nbsp; / &nbsp;&nbsp;/ &nbsp;&nbsp;٢٠</div>
        <div class="section"><span>بيانات مقدم الطلب / المنشأة</span></div>
        <div class="fields">
            <div class="field"><b>مقدم الطلب:</b> <span class="fill"></span></div><div class="field"><b>صفة مقدم الطلب:</b> <span class="fill"></span></div><div class="field"><b>رقم تأميني:</b> <span class="boxes">@for($i=0;$i<9;$i++)<i></i>@endfor</span></div>
            <div class="field"><b>رقم قومي:</b> <span class="boxes">@for($i=0;$i<14;$i++)<i></i>@endfor</span></div><div class="field"></div><div class="field"><b>رقم التليفون:</b> <span class="fill"></span></div>
            <div class="field"><b>اسم المنشأة:</b> <span class="fill"></span></div><div class="field"><b>قطاع المنشأة:</b> <span class="fill"></span></div><div class="field"><b>رقم التسجيل الضريبي:</b> <span class="fill"></span></div>
            <div class="field"><b>رقم السجل التجاري:</b> <span class="fill"></span></div><div class="field"><b>رقم السجل التجاري الموحد:</b> <span class="fill"></span></div><div class="field"></div>
        </div>
        <div class="section"><span>بيانات المؤمن عليهم</span></div>
        <table>
            <colgroup><col style="width:22.1%"><col style="width:17.5%"><col style="width:24.2%"><col style="width:4.5%"><col style="width:5.1%"><col style="width:6.1%"><col style="width:10.5%"><col style="width:10%"></colgroup>
            <thead><tr><th>الرقم التأميني</th><th>اسم المؤمن عليه</th><th>الرقم القومي</th><th colspan="3">تاريخ الالتحاق</th><th>أجر الاشتراك</th><th>الأجر الشامل</th></tr><tr><th></th><th></th><th></th><th>يوم</th><th>شهر</th><th>سنة</th><th>جنيه</th><th>جنيه</th></tr></thead>
            <tbody>@for($row = 0; $row < 10; $row++)<tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>@endfor</tbody>
        </table>
    </section>
    <section class="page">
        <div class="declaration-title">إقرار</div>
        <div class="declaration">أقر أنا .......................................................... بصفتي .......................................................... بأن جميع البيانات الواردة بهذا النموذج وملحقاته صحيحة (الأجر الشامل لحساب اشتراكات التأمين الصحي الشامل)، وأني أتحمل المسؤولية القانونية عن صحة البيانات الواردة بهذه الاستمارة، وفي حالة المخالفة تطبق العقوبات المنصوص عليها بالقانون رقم ١٤٨ لسنة ٢٠١٩ مع عدم الإخلال بأي عقوبات أشد واردة في قانون العقوبات، وعلى أن يتم موافاة الهيئة بأي تعديلات تطرأ على أجور العاملين بالمنشأة في أي تاريخ خلال العام يصدر فيه قرار أو قانون بتعديل الأجور وكذلك في يناير من كل عام.</div>
        <div class="signature">توقيع صاحب العمل أو المدير المسؤول<br>..........................................................</div>
        <div class="date-line">تحريرًا في: &nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp; ٢٠</div>
    </section>
</body>
</html>
