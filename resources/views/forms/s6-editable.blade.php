@php
    $pdfAsset = function (string $path, string $mime): string {
        $file = public_path($path);
        return file_exists($file) ? 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file)) : asset($path);
    };
    $mudirFont = $pdfAsset('fonts/MudirMT.ttf', 'font/ttf');
    $ptFont = $pdfAsset('fonts/PTBoldHeading.ttf', 'font/ttf');
    $koufiFont = $pdfAsset('fonts/MonotypeKoufi-Bold.ttf', 'font/ttf');
    $logoImage = $pdfAsset('images/nosi-logo.jpg', 'image/jpeg');
    $boxes = function ($value, $count, $right = false) {
        $value = (string) $value;
        if ($right) {
            $value = str_pad($value, $count, ' ', STR_PAD_LEFT);
        }
        $html = '<span class="boxes">';
        for ($i = 0; $i < $count; $i++) {
            $html .= '<i>' . e(mb_substr($value, $i, 1)) . '</i>';
        }
        return $html . '</span>';
    };
    $font = fn($value) => mb_strlen((string) $value) > 25 ? '10pt' : '13pt';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <title>س٦ - {{ $d['name'] }}</title>
    <style>
        @font-face {
            font-family: 'Mudir MT';
            src: url('{{ $mudirFont }}') format('truetype');
            font-weight: 400;
            font-style: normal
        }

        @font-face {
            font-family: 'PT Bold Heading';
            src: url('{{ $ptFont }}') format('truetype');
            font-weight: 700;
            font-style: normal
        }

        @font-face {
            font-family: 'Monotype Koufi';
            src: url('{{ $koufiFont }}') format('truetype');
            font-weight: 700;
            font-style: normal
        }

        @page {
            size: A4 portrait;
            margin: 0
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact
        }

        html,
        body {
            margin: 0;
            padding: 0;
            color: #000
        }

        body {
            font-family: 'Mudir MT', 'Cairo', Tahoma, sans-serif;
            font-size: 11.8pt;
            direction: rtl;
            background: #ddd
        }

        .tools {
            width: 210mm;
            margin: 12px auto;
            display: flex;
            gap: 8px
        }

        .tools button,
        .tools a {
            padding: 9px 15px;
            border: 0;
            border-radius: 5px;
            background: #145ec8;
            color: #fff;
            text-decoration: none;
            font: inherit;
            cursor: pointer
        }

        .tools a {
            background: #fff;
            color: #19345b;
            border: 1px solid #c8d2df
        }

        .tools .pdf {
            background: #198754
        }

        .pdf-output .tools {
            display: none !important
        }

        .page {
            width: 210mm;
            height: 297mm;
            padding: 8mm 10mm 5mm;
            margin: 0 auto 12px;
            background: #fff;
            overflow: hidden;
            page-break-after: always
        }

        .page:last-child {
            page-break-after: auto
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3pt double #000;
            padding-bottom: 4pt;
        }

        .header .office {
            font-weight: 700;
            font-size: 12pt;
            line-height: 1.5
        }

        .header img {
            height: 54pt
        }

        .header .no {
            width: 155pt;
            text-align: left;
            font-weight: 700
        }

        .title,
        .subtitle {
            font-family: 'PT Bold Heading', 'Mudir MT', sans-serif
        }

        .bold-label { font-family: 'PT Bold Heading', 'Mudir MT', sans-serif; }

        .title {
            text-align: center;
            font-weight: 800;
            font-size: 16pt;
            margin: 8pt 0 5pt;
            text-decoration: underline
        }

        .section {
            display: flex;
            align-items: center;
            margin: 10pt 0 7pt
        }

        .section:before,
        .section:after {
            content: '';
            flex: 1;
            border-top: 2.5pt solid #000
        }

        .section span {
            border: 1.5pt solid #000;
            border-radius: 9pt;
            padding: 3pt 22pt;
            min-width: 170pt;
            text-align: center;
            font-weight: 700;
            white-space: nowrap
        }

        .row {
            display: flex;
            align-items: flex-end;
            min-height: 21pt;
            margin-bottom: 3pt;
            white-space: nowrap
        }

        .label {
            flex: none
        }

        .label-wide {
            flex: none;
            width: 112pt
        }

        .line {
            flex: 1;
            border-bottom: 1px dotted #000;
            min-height: 18pt;
            padding: 0 4pt;
            text-align: center;
            overflow: hidden
        }

        .row > .line {
            text-align: right
        }

        .gap {
            width: 8pt;
            flex: none
        }

        .boxes {
            display: inline-flex;
            direction: ltr;
            flex: none
        }

        .boxes i {
            font-style: normal;
            width: 19pt;
            height: 22pt;
            border: 1px solid #000;
            margin-left: -1px;
            text-align: center;
            font-family: Arial, sans-serif;
            font-size: 13pt;
            line-height: 20pt
        }

        .slash {
            width: 12pt;
            flex: none;
            text-align: center;
            font-family: Arial
        }

        .declaration {
            font-weight: 700;
            text-align: center;
            margin: 11pt 0;
            line-height: 1.75
        }

        .notice {
            font-size: 12pt;
            line-height: 1.6;
            border-top: 1pt solid #000;
            margin-top: 22pt;
            padding-top: 9pt
        }

        .instruction {
            font-size: 11.5pt;
            font-weight: 700;
            line-height: 1.65;
            text-align: justify
        }

        .subtitle {
            text-align: center;
            font-size: 15pt;
            font-weight: 800;
            margin: 8pt 0 10pt
        }

        .signature {
            margin-top: 12pt;
            display: flex;
            justify-content: space-between;
            font-weight: 700
        }

        .note {
            font-weight: 700;
            margin-top: 18pt;
            border-top: 1pt solid #000;
            padding-top: 8pt;
            white-space: normal
        }

        .back-note {
            margin-top: 20pt;
            text-align: left;
            font-weight: 700;
            font-size: 12pt;
            direction: rtl;
        }

        /* Fit all first-page declarations, signatures and the back-note inside A4. */
        .page:first-of-type { padding-top: .35in; padding-bottom: .2in; }
        .page:first-of-type .header { padding-bottom: 2pt; }
        .page:first-of-type .header img { height: 44pt; }
        .page:first-of-type .title { font-size: 14pt; margin: 4pt 0 3pt; }
        .page:first-of-type .section { margin-top: 4pt; margin-bottom: 2pt; }
        .page:first-of-type .section span { padding-top: 1pt; padding-bottom: 1pt; }
        .page:first-of-type .row { min-height: 15pt; margin-bottom: 1pt; }
        .page:first-of-type .line { min-height: 12pt; }
        .page:first-of-type .declaration { margin: 7pt 0; line-height: 1.55; }
        .page:first-of-type .note { margin-top: 3pt; padding-top: 2pt; font-size: 10pt; line-height: 1.25; }
        .page:first-of-type .note + .row { margin-top: 3pt; min-height: 16pt; }
        .page:first-of-type .back-note { margin-top: 12pt; font-size: 11pt; }

        @media print {
            body {
                background: #fff
            }

            .tools {
                display: none
            }

            .page {
                margin: 0
            }
        }

        @media screen and (max-width:850px) {

            .tools,
            .page {
                width: 100vw
            }

            .page {
                height: 164.7vw
            }
        }
    </style>
</head>

<body class="{{ request()->boolean('pdf') ? 'pdf-output' : '' }}">
    <div class="tools"><button type="button" onclick="window.print()">طباعة نموذج س٦</button><a
            href="{{ route('insurance.index') }}">العودة للتأمينات</a></div>
    <section class="page">
        <div class="header">
            <div class="office">الهيئة القومية للتأمين الاجتماعي<br>مكتب: <span class="line"
                    style="display:inline-block;min-width:150pt">{{ $d['office'] }}</span></div><img
                src="{{ $logoImage }}" alt="">
            <div class="no">نموذج رقم ( ٦ )</div>
        </div>
        <div class="title">إخطار بانتهاء اشتراك مؤمن عليه</div>
        <div class="section"><span>بيانات مقدم الطلب</span></div>
        <div class="row"><span class="label-wide">مقدم الطلب:</span><span
                class="line">{{ $d['applicant_name'] }}</span><span class="gap"></span><span class="label">صفة
                مقدم الطلب:</span><span class="line">{{ $d['applicant_role'] }}</span></div>
        <div class="row"><span class="label-wide">رقم تأميني:</span>{!! $boxes($d['applicant_ins_no'], 9) !!}<span
                class="gap"></span><span class="label">رقم التليفون:</span><span
                class="line">{{ $d['applicant_phone'] }}</span></div>
        <div class="row"><span class="label-wide">رقم قومي:</span>{!! $boxes($d['applicant_nid'], 14) !!}</div>
        <div class="section"><span>بيانات المؤمن عليه</span></div>
        <div class="row"><span class="label-wide">الرقم التأميني:</span>{!! $boxes($d['ins_no'], 9) !!}<span
                class="gap"></span><span class="label">الاسم:</span><span class="line"
                style="font-size:{{ $font($d['name']) }}">{{ $d['name'] }}</span></div>
        <div class="row"><span class="label-wide">الرقم القومي:</span>{!! $boxes($d['nid'], 14) !!}</div>
        <div class="row"><span class="label-wide">رقم المنشأة:</span>{!! $boxes($d['est_no'], 9, true) !!}<span
                class="gap"></span><span class="label">اسم المنشأة:</span><span class="line"
                style="font-size:{{ $font($d['est_name']) }}">{{ $d['est_name'] }}</span></div>
        <div class="row"><span class="label-wide">تاريخ انتهاء الاشتراك:</span>{!! $boxes($d['end_day'], 2) !!}<span
                class="slash">/</span>{!! $boxes($d['end_month'], 2) !!}<span class="slash">/</span>{!! $boxes($d['end_year'], 4) !!}<span
                class="gap"></span><span class="label">سبب انتهاء الاشتراك:</span><span
                class="line">{{ $d['end_reason'] }}</span></div>
        <div class="section"><span>إقرار المؤمن عليه والمدير المسؤول</span></div>
        <div class="declaration">أقر أن البيانات بعاليه صحيحة وأن المؤمن عليه تسلم صورة من هذا الإخطار.</div>
        <div class="row"><span class="label-wide bold-label">توقيع المؤمن عليه:</span><span class="line"></span><span
                class="gap"></span><span class="label">توقيع المدير المسؤول:</span><span class="line"></span>
        </div>
        <div class="row"><span class="label-wide">تم مطابقة التوقيع بمعرفتي /</span><span class="line"></span>
        </div>
        <div class="section"><span>إقرار المدير المسؤول في حالة وجود نزاع</span></div>
        <div class="declaration">أقر أن البيانات بعاليه صحيحة وأنني أرسلت صورة من هذا الإخطار إلى المؤمن عليه بخطاب موصى
            عليه بعلم الوصول برقم ........................................ بتاريخ&nbsp; {{ $d['issue_date'] }}</div>
        <div class="row"><span class="label-wide">تم مطابقة التوقيع بمعرفتي</span></div>
        <div class="row"><span class="label-wide">توقيع المدير المسؤول:</span><span class="line"></span></div>
        <div class="section"><span>إقرار المؤمن عليه في حالة وجود نزاع</span></div>
        <div class="declaration">أقر أن البيانات بعاليه صحيحة وأن صاحب العمل امتنع عن ( توقيع / تقديم ) نموذج انتهاء
            الخدمة.</div>
        <div class="row"><span class="label-wide bold-label">توقيع المؤمن عليه:</span><span class="line"></span><span
                class="gap"></span><span class="label">التاريخ:</span><span
                class="line">{{ $d['issue_date'] }}</span></div>
        <div class="note"><span class="bold-label">ملحوظــة</span>: يلزم التأكد من توقيع كل من العامل وأصحاب العمل على الإقرار الموضح خلف النموذج.</div>
        <div class="row" style="margin-top:15pt"><span class="label-wide">توقيع مقدم الطلب:</span><span
                class="line"></span><span class="gap"></span><span class="label">التاريخ:</span><span
                class="line">{{ $d['issue_date'] }}</span></div>
        <div class="back-note">(انظر خلفه)</div>
    </section>
    <section class="page">
        <div class="subtitle">إرشادات</div>
        <div class="instruction">١. يحرر هذا النموذج ويرسل للهيئة خلال أسبوع من تاريخ تحقق إحدى الوقائع الآتية:<br>أ.
            انتهاء خدمة المؤمن عليه.<br>ب. انتهاء مدة التلمذة الصناعية أو التدرج.<br>ج. انتهاء العمل بالمشروع الصيفي
            للطلبة.<br><br>٢. في حالة إخلال صاحب العمل بالإخطار في الموعد المشار إليه، يلتزم بأداء مبلغ إضافي يقدر بنسبة
            من قيمة الاشتراك المستحق عن المدة وفقاً للقواعد المنظمة.</div>
        <div class="subtitle" style="margin-top:22pt">إقرار</div>
        <div class="row"><span class="label-wide">اسم المنشأة:</span><span
                class="line">{{ $d['est_name'] }}</span><span class="gap"></span><span class="label">رقمها
                التأميني:</span>{!! $boxes($d['est_no'], 9, true) !!}</div>
        <div class="row"><span class="label-wide">العنوان:</span><span class="line">{{ $d['address'] }}</span>
        </div>
        <div class="row"><span class="label-wide">اسم المؤمن عليه:</span><span
                class="line">{{ $d['name'] }}</span><span class="gap"></span><span class="label">رقمه
                التأميني:</span>{!! $boxes($d['ins_no'], 9) !!}</div>
        <div class="instruction" style="margin-top:18pt">٣. أقر أنا الموقع أدناه بأنني قد قمت بسحب البطاقة العلاجية من
            المؤمن عليه وتم تسليمها لفرع الهيئة المعنية بالتأمين الصحي، وفي حالة ظهور ما يخالف ذلك أكون مسؤولاً بالتضامن
            مع العامل في مواجهة الهيئة عن كافة مصاريف العلاج والرعاية الطبية وتعويض الانتفاع حتى بعد انتهاء الخدمة.
        </div>
        <div class="signature"><span class="bold-label">توقيع المؤمن عليه<br><br>(　　　　　　　　　)</span><span class="bold-label">توقيع صاحب
                العمل<br><br>(　　　　　　　　　)</span></div>
        <div class="instruction" style="margin-top:38pt">٤. أقر أنا الموقع أدناه بأن المؤمن عليه محل هذا النموذج قد
            رفض تسليم البطاقة العلاجية، وقمت بإخطار الهيئة المعنية بالتأمين الصحي ببيانات المؤمن عليه لإيقاف التعامل
            معه.</div>
        <div class="signature"><span class="bold-label">توقيع صاحب العمل<br><br>(　　　　　　　　　)</span></div>
    </section>
</body>

</html>
