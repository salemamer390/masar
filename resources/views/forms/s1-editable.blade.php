{{-- resources/views/forms/s1.blade.php  —  نموذج رقم (1) طلب اشتراك مؤمن عليه (ورق فاضي، Legal) --}}
@php
    $pdfAsset = function (string $path, string $mime): string {
        $file = public_path($path);
        return file_exists($file) ? 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file)) : asset($path);
    };
    $mudirFont = $pdfAsset('fonts/MudirMT.ttf', 'font/ttf');
    $ptFont = $pdfAsset('fonts/PTBoldHeading.ttf', 'font/ttf');
    $logoImage = $pdfAsset('images/nosi-logo.jpg', 'image/jpeg');
    // خانات الأرقام: الرقم يتقرأ من الشمال لليمين زي الاستمارة الأصلية (الأرقام المالية/رقم المنشأة بتتحط من اليمين)
    $boxes = function ($value, $n, $right = false) {
        $value = (string) $value;
        if ($right) { $value = str_pad($value, $n, ' ', STR_PAD_LEFT); }
        $out = '<span class="bx">';
        for ($i = 0; $i < $n; $i++) {
            $out .= '<i>' . e(mb_substr($value, $i, 1)) . '</i>';
        }
        return $out . '</span>';
    };
    // تصغير الخط للأسماء الطويلة
    $fs = fn ($t) => mb_strlen((string) $t) > 26 ? '9.5pt' : (mb_strlen((string) $t) > 16 ? '11.5pt' : '14pt');
    // علامة ✔ لو القيمة = المطلوب
    $chk = fn ($value, $eq = true) => ((string) $value === (string) $eq) ? '✔' : '';
@endphp
@php($bulkMode = $bulk ?? false)
@if (! $bulkMode)
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>{{ __('س1 -') }} {{ $d['name'] }}</title>
@endif
<style>
  /* ضع ملفات الخطوط في public/fonts */
  @font-face { font-family: 'Mudir MT';        src: url('{{ $mudirFont }}') format('truetype'); }
  @font-face { font-family: 'PT Bold Heading'; src: url('{{ $ptFont }}') format('truetype'); }
  @page { size: A4 portrait; margin: 0; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  html, body { margin: 0; padding: 0; color: #000; }
  body { font-family: 'Mudir MT', 'Cairo', 'Tahoma', sans-serif; font-size: 11.8pt; direction: rtl; background: #ddd; }
  .page { width: 210mm; height: 297mm; padding: 8mm 10mm 5mm; margin: 0 auto 12px; background: #fff; overflow: hidden; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  @media print { body { background: #fff; } .page { margin: 0; } }
  .tools { width: 210mm; margin: 12px auto; display: flex; gap: 8px; direction: rtl; }
  .tools button, .tools a { padding: 9px 16px; border: 0; border-radius: 5px; background: #145ec8; color: #fff; font: inherit; cursor: pointer; text-decoration: none; }
  .tools a.pdf { background: #198754; }
  @media print { .tools { display: none !important; } }
  .pdf-output .tools { display: none !important; }

  .h { font-family: 'PT Bold Heading', 'Mudir MT', sans-serif; }
  .hdr { display: flex; align-items: center; justify-content: space-between; border-bottom: 3pt double #000; padding-bottom: 4pt; }
  .hdr .c { text-align: center; } .hdr img { height: 54pt; }
  .hdr .org { font-size: 12pt; line-height: 1.5; } .hdr .org .v { display: inline-block; min-width: 130pt; }
  .hdr .no { font-size: 12pt; width: 160pt; text-align: left; }
  .title { text-align: center; font-size: 16pt; margin: 6pt 0 4pt; letter-spacing: 0; font-family: 'Mudir MT', 'Cairo', Tahoma, sans-serif; font-weight: 700; }

  .sec { display: flex; align-items: center; margin: 7pt 0 5pt; }
  .sec:before, .sec:after { content: ''; flex: 1; border-top: 2.5pt solid #000; }
  .sec span { border: 1.5pt solid #000; border-radius: 9pt; padding: 1pt 24pt; min-width: 150pt; text-align: center; font-size: 14pt; white-space: nowrap; }

  .r { display: flex; align-items: flex-end; min-height: 21pt; margin-bottom: 3pt; white-space: nowrap; }
  .l { flex: none; }  .l1 { flex: none; width: 112pt; }  .l + .v, .l + .bx, .l + .cb { margin-right: 2pt; }
  .v { flex: 1; border-bottom: 1px dotted #000; min-height: 18pt; padding: 0 4pt; text-align: center; overflow: hidden; font-size: 14pt; }
  /* إبقاء قيمة الحقل ملاصقة للتسمية بدل تمركزها في منتصف الخط الطويل */
  .r > .v { text-align: right; }
  .gap { flex: none; width: 8pt; }
  .bx { display: inline-flex; flex: none; direction: ltr; }
  .bx i { font-style: normal; width: 19pt; height: 22pt; border: 1px solid #000; margin-left: -1px; text-align: center; font-family: Arial, sans-serif; font-size: 13pt; line-height: 20pt; }
  .sl { flex: none; width: 12pt; text-align: center; font-family: Arial, sans-serif; }
  .cb { flex: none; display: inline-block; width: 19pt; height: 19pt; border: 1px solid #000; text-align: center; font-family: 'Segoe UI Symbol', Arial, sans-serif; font-size: 14pt; line-height: 18pt; margin-left: 4pt; }
  .num { flex: none; display: inline-block; width: 19pt; height: 21pt; border: 1px solid #000; text-align: center; font-family: 'Times New Roman', serif; line-height: 19pt; }
  .money { display: flex; flex-direction: column; flex: none; }
  .money small { border: 1px solid #000; border-bottom: 0; text-align: center; font-size: 8pt; line-height: 11pt; margin-left: -1px; }
  .note { font-size: 10.5pt; margin-top: 4pt; line-height: 1.35; white-space: normal; }

  /* صفحة الضهر */
  .txt { line-height: 24pt; text-align: justify; }
  .it { display: flex; line-height: 24pt; text-align: justify; } .it b { flex: none; width: 27pt; font-weight: normal; }
  .sub { padding-right: 27pt; }
  .sig { margin: 4pt 0 0 0; } .sig .p { margin-top: 16pt; font-size: 12pt; }
  .center { text-align: center; letter-spacing: 0; }
  .page:not(.page-front) .center.h { font-family: 'Mudir MT', 'Cairo', Tahoma, sans-serif; font-weight: 700; }
  .page-front .sec { margin-top: 5pt; margin-bottom: 3pt; }
  .page-front .txt { line-height: 18pt; }
  .page-front .sig { margin-top: 2pt; }
  /* الصفحة الأولى تحتوي على آخر ملاحظة وتوقيع؛ نترك لها مساحة إضافية داخل A4 */
  .page-front { padding-top: 4mm; padding-bottom: 2mm; }
  .page-front .hdr { padding-bottom: 2pt; }
  .page-front .hdr img { height: 46pt; }
  .page-front .title { font-size: 14pt; margin: 3pt 0 2pt; }
  .page-front .sec span { font-size: 12pt; padding: 0 18pt; }
  .page-front .r { min-height: 15pt; margin-bottom: 0; }
  .page-front .v { min-height: 13pt; font-size: 11pt; }
  .page-front .bx i { width: 17pt; height: 18pt; font-size: 11pt; line-height: 17pt; }
  .page-front .cb { width: 17pt; height: 17pt; line-height: 16pt; }
  .page-front .num { width: 17pt; height: 18pt; line-height: 17pt; }
  .page-front .note { font-size: 9pt; line-height: 1.2; margin-top: 1pt; }
  .page-front .sec { margin-top: 2pt; margin-bottom: 1pt; }
  .page-front .r { min-height: 13pt; }
  .page-front .v { min-height: 11pt; }
  .page-front .sig .p { margin-top: 6pt; }
  .page1-footer { position: static; margin-top: 3pt; background: #fff; }
  .page1-footer .sec { margin-top: 0; }
  .page:not(.page-front) .sig { text-align: left; direction: rtl; }
  .page:not(.page-front) .sig .h { text-align: left; padding-right: 0 !important; }
  .page:not(.page-front) .sig .p { text-align: left; padding-right: 0 !important; }
</style>
@if (! $bulkMode)
</head>
<body class="{{ request()->boolean('pdf') ? 'pdf-output' : '' }}">
<div class="tools" aria-label="{{ __('إجراءات النموذج') }}">
  <button type="button" onclick="window.print()">{{ __('طباعة') }}</button>
</div>
@endif

{{-- ======================= صفحة 1 (الوش) ======================= --}}
<div class="page page-front">
  <div class="hdr h">
    <div class="org"><div>{{ __('الهيئة القومية للتأمين الاجتماعي') }}</div><div>{{ __('مكتب :') }} <span class="v" style="border-bottom:1px dotted #000">{{ $d['office'] }}</span></div></div>
    <div class="c"><img src="{{ $logoImage }}" alt=""></div>
    <div class="no">{{ __('نموذج رقم ( ١ )') }}</div>
  </div>
  <div class="title h">{{ __('طلب اشتراك مؤمن عليه') }}</div>

  <div class="r"><span class="l" style="width:46pt">{{ __('الفئة :') }}</span>
    <span class="num">{{ __('١') }}</span><span class="l" style="margin:0 4pt 0 0">{{ __('عاملين لدى الغير') }}</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 1) }}</span>
    <span style="width:34pt"></span>
    <span class="num">{{ __('٢') }}</span><span class="l" style="margin:0 4pt 0 0">{{ __('أصحاب أعمال لهم منشآت') }}</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 2) }}</span>
  </div>
  <div class="r"><span class="l" style="width:46pt"></span>
    <span class="num">{{ __('٣') }}</span><span class="l" style="margin:0 4pt 0 0">{{ __('العاملين بالمخابز') }}</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 3) }}</span>
  </div>

  <div class="sec"><span>{{ __('بيانات مقدم الطلب') }}</span></div>
  <div class="r"><span class="l1">{{ __('مقدم الطلب :') }}</span><span class="v" style="flex:1.2">{{ $d['applicant_name'] }}</span><span class="gap"></span><span class="l">{{ __('صفة مقدم الطلب :') }}</span><span class="v" style="font-size:{{ $fs($d['applicant_role']) }}">{{ $d['applicant_role'] }}</span></div>
  <div class="r"><span class="l1">{{ __('رقم تأميني :') }}</span>{!! $boxes($d['applicant_ins_no'], 9) !!}<span class="gap"></span><span class="l">{{ __('رقم التليفون :') }}</span><span class="v" dir="ltr">{{ $d['applicant_phone'] }}</span></div>
  <div class="r"><span class="l1">{{ __('رقم قومي :') }}</span>{!! $boxes($d['applicant_nid'], 14) !!}</div>

  <div class="sec"><span>{{ __('بيانات المؤمن عليه') }}</span></div>
  <div class="r"><span class="l1">{{ __('الرقم التأميني :') }}</span>{!! $boxes($d['ins_no'], 9) !!}<span class="gap"></span><span class="l">{{ __('اسم المؤمن عليه :') }}</span><span class="v" style="font-size:{{ $fs($d['name']) }}">{{ $d['name'] }}</span></div>
  <div class="r"><span class="l1">{{ __('الرقم القومي :') }}</span>{!! $boxes($d['nid'], 14) !!}<span class="gap"></span><span class="l">{{ __('الجنسية :') }}</span><span class="v">{{ $d['nationality'] }}</span></div>
  <div class="r"><span class="l1">{{ __('المؤهل :') }}</span><span class="v">{{ $d['qualification'] }}</span><span class="gap"></span><span class="l">{{ __('المهنة :') }}</span><span class="v" style="font-size:{{ $fs($d['job']) }}">{{ $d['job'] }}</span></div>
  <div class="r"><span class="l1">{{ __('تاريخ بدء الاشتراك :') }}</span>{!! $boxes($d['start_day'], 2) !!}<span class="sl">/</span>{!! $boxes($d['start_month'], 2) !!}<span class="sl">/</span>{!! $boxes($d['start_year'], 4) !!}<span class="gap"></span><span class="l">{{ __('القطاع :') }}</span><span class="v">{{ $d['sector'] }}</span></div>
  <div class="r"><span class="l1">{{ __('كود الاشتراك :') }}</span><span class="v">{{ $d['sub_code'] }}</span><span class="gap"></span><span class="l">{{ __('نوع المدة :') }}</span><span class="v">{{ $d['period_type'] }}</span></div>
  <div class="r" style="margin-top:2pt"><span class="l1">{{ __('أجر /دخل الاشتراك :') }}</span>
    <span class="money"><small>{{ __('جنيــه') }}</small>{!! $boxes($d['wage'], 6, true) !!}</span><span class="gap"></span>
    <span class="l">{{ __('الأجر الشامل :') }}</span>
    <span class="money" style="margin-right:4pt"><small>{{ __('جنيــه') }}</small>{!! $boxes($d['total_wage'], 6, true) !!}</span></div>
  <div class="r"><span class="l">{{ __('بيانات العجز إن وجدت :') }}</span><span class="l" style="margin-right:6pt">{{ __('تاريخ بداية العجز :') }}</span>
    {!! $boxes($d['dis_day'], 2) !!}<span class="sl">/</span>{!! $boxes($d['dis_month'], 2) !!}<span class="sl">/</span>{!! $boxes($d['dis_year'], 4) !!}
    <span class="gap"></span><span class="l">{{ __('نسبة العجز :') }}</span><span class="v">{{ $d['dis_percent'] }}</span><span class="l" style="margin-right:4pt">%</span></div>
  <div class="r"><span class="l">{{ __('استيفاء الكشف الطبي الابتدائي :') }}</span><span class="l" style="margin-right:8pt">{{ __('نعم') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['medical'], 1) }}</span><span class="l" style="margin-right:10pt">{{ __('لا') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['medical'], 0) }}</span></div>

  <div class="sec"><span>{{ __('بيانات المنشأة') }}</span></div>
  <div class="r"><span class="l1">{{ __('نوع المنشأة :') }}</span>
    <span class="l">{{ __('نمطي') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 1) }}</span><span style="width:6pt"></span>
    <span class="l">{{ __('سيارة') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 2) }}</span><span style="width:6pt"></span>
    <span class="l">{{ __('مركب صيد') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 3) }}</span><span style="width:6pt"></span>
    <span class="l">{{ __('مخابز بلدية') }}</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 4) }}</span></div>
  <div class="r"><span class="l1">{{ __('اسم المنشأة :') }}</span><span class="v" style="font-size:{{ $fs($d['est_name']) }}">{{ $d['est_name'] }}</span><span class="gap"></span><span class="l">{{ __('رقم المنشأة :') }}</span>{!! $boxes($d['est_no'], 9, true) !!}</div>

  <div class="sec"><span>{{ __('بيانات محل إقامة المؤمن عليه') }}</span></div>
  <div class="r"><span class="l1">{{ __('عقار رقم :') }}</span><span class="v" style="flex:.6">{{ $d['addr_building'] }}</span><span class="gap"></span><span class="l">{{ __('شارع :') }}</span><span class="v" style="flex:1.6">{{ $d['addr_street'] }}</span><span class="gap"></span><span class="l">{{ __('قرية :') }}</span><span class="v">{{ $d['addr_village'] }}</span></div>
  <div class="r"><span class="l1">{{ __('قسم / مركز :') }}</span><span class="v">{{ $d['addr_district'] }}</span><span class="gap"></span><span class="l">{{ __('محافظة :') }}</span><span class="v">{{ $d['addr_governorate'] }}</span></div>

  <div class="sec"><span>{{ __('التوقيع') }}</span></div>
  <div class="r"><span class="l1 h">{{ __('توقيع المؤمن عليه :') }}</span><span class="v" style="flex:.9"></span><span class="gap" style="width:40pt"></span><span class="l h" style="flex:1;text-align:center">{{ __('توقيع صاحب العمل / المدير المسئول') }}</span></div>
  <div class="r" style="min-height:30pt"><span class="l1">{{ __('رقم التليفون :') }}</span><span class="v" dir="ltr" style="flex:.9">{{ $d['phone'] }}</span><span class="gap" style="width:40pt"></span><span class="l">(</span><span class="v" style="flex:.8"></span><span class="l">)</span></div>
  <div class="r"><span class="l1">{{ __('تحريراً في :') }}</span><span class="l" dir="ltr" style="font-family:Arial">{{ $d['issue_date'] }}</span></div>

  <div class="page1-footer">
    <div class="sec"><span>{{ __('مطابقة التوقيع') }}</span></div>
    <div class="r"><span class="l">{{ __('توقيع الموظف المختص بالمطابقة :') }}</span><span class="v"></span><span class="gap"></span><span class="l">{{ __('تاريخ المطابقة :') }}</span><span class="l" style="font-family:Arial;margin-right:6pt">&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;</span></div>
    <div class="note"><span class="h">{{ __('ملحوظة :') }}</span> {{ __('على صاحب العمل والعامل الاطلاع على التوجيهات الموضحة خلف النموذج مع التوقيع على الإقرار. &nbsp;&nbsp;(انظر خلفه)') }}</div>
  </div>
</div>

{{-- ======================= صفحة 2 (الضهر) ======================= --}}
<div class="page">
  <div class="center h" style="font-size:14pt;margin-bottom:2pt">{{ __('إرشادات') }}</div>
  <div class="it"><b>{{ __('١.') }}</b><span>{{ __('على صاحب العمل أن يرسل هذا النموذج مع طلب اشتراكه في الهيئة لأول مرة وخلال أسبوعين على الأكثر من تاريخ التحاق أي عامل جديد بالعمل لديه سواء كان التحاقاً نهائياً أو تحت الاختبار.') }}</span></div>
  <div class="it"><b>{{ __('٢.') }}</b><span>{{ __('التوقيع على هذا النموذج بما يفيد الاطلاع والموافقة على جميع البيانات الواردة به ولا يجوز لمـــــن وقَّع عليه أن يعارض في تلك البيانات أمام الهيئة وله أن يلجأ إلى مكتب علاقات العمل المختص أو القضاء.') }}</span></div>
  <div class="it"><b>{{ __('٣.') }}</b><span>{{ __('يستخدم هذا النموذج كطلب اشتراك في تأمين إصابات العمل فقط بالنسبة للفئات التالية:') }}</span></div>
  <div class="it sub"><b>{{ __('(أ)') }}</b><span>{{ __('من تجاوز سن الشيخوخة وأوقف انتفاعه بتأمين الشيخوخة والعجز والوفاة.') }}</span></div>
  <div class="it sub"><b>{{ __('(ب)') }}</b><span>{{ __('العاملون الذين يخضعون لأحكام قانون العمل ممن تقل أعمارهم عن ١٨ سنة.') }}</span></div>
  <div class="it sub"><b>{{ __('(ج)') }}</b><span>{{ __('العاملون المتدرجون والتلاميذ الصناعيون والطلاب المشتغلون في مشروعات التشغيل الصيفـــي والخدمة العامة، ويشترط اعتماد النموذج المحرر لهم من المدير المسئول بالهيئة التي تشرف على التلمذة الصناعية، والتدريب مع ختمها بخاتم هذه الجهة مع إرفاق نســـخة من عقد عمل المتدرب أو المســـتند المثبت لنوع العمل في جميع هذه الحالات.') }}</span></div>
  <div class="it sub"><b>{{ __('(د)') }}</b><span>{{ __('يقتصر استيفاء الأجر على الفئات التي يتقاضى فيها المؤمن عليه أجراً من صاحب العمل.') }}</span></div>

  <div class="center h" style="font-size:14pt;margin:16pt 0 2pt">{{ __('إقرار') }}</div>
  <div class="r"><span class="l" style="width:92pt">{{ __('اســـم المنشأة :') }}</span><span class="v" style="flex:1.6;font-size:{{ $fs($d['est_name']) }}">{{ $d['est_name'] }}</span><span class="gap"></span><span class="l">{{ __('رقمها التأميني :') }}</span><span class="v">{{ $d['est_no'] }}</span></div>
  <div class="r"><span class="l" style="width:92pt">{{ __('العنـــوان :') }}</span><span class="v">{{ $d['est_address'] }}</span></div>
  <div class="txt" style="margin-top:4pt">{{ __('أقر أنا الموقع على هذا النموذج بالالتزام بعرض المؤمن عليه على اللجنة الطبية المختصـــة بالهيئة المعنية بالتأمين الصحي أو الجهة الطبية المختصـــة لإجراء الفحص الطبي الأولي وإثبات حالته الصـــحية وقت توقيع الكشـــف الطبي مع الالتزام بموافاة الهيئة بتقرير اللياقة الطبية الصـــادر من الجهة الطبية المختصـــة عن حالته الصـــحية خلال أسبوعين على الأكثر من تاريخ التحاقه بالعمل تطبيقاً لأحكام قانون العمل.') }}</div>
  <div class="sig"><div class="h" style="font-size:14pt;margin-top:6pt">{{ __('توقيع (صاحب العمل / المدير المسئول)') }}</div><div class="p">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div></div>

  <div class="txt" style="margin-top:20pt">{{ __('أقر أنا') }} <span style="border-bottom:1px dotted #000;padding:0 14pt">{{ $d['name'] }}</span> {{ __('العامل بالمنشـــأة عاليه بأن أثبت حالتي الصـــحية أمام اللجنة الطبية المختصـــة بالهيئة المعنية بالتأمين الصـــحي أو الجهة الطبية المختصـــة وموافاة الهيئة بالتقرير الطبي عن حالتي الصـــحية خلال أسبوعين من تاريخ التحاقي بالعمل وفي حالة عدم قيامي بذلك فإن الهيئة القومية للتأمين الاجتماعي ليس عليها أدنى التزام قانوني بعرضي على اللجان الطبية لإثبات العجز أو صرف أية مستحقات تأمينية تترتب على العجز أياً كان نوعه السابق أو المعاصر لتاريخ الالتحاق بالعمل.') }}</div>
  <div class="sig"><div class="h" style="font-size:14pt;margin-top:6pt;padding-right:40pt">{{ __('توقيع المؤمن عليه') }}</div><div class="p" style="padding-right:10pt">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div></div>
</div>

@if(! $bulkMode && request('print'))
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
@endif
@if (! $bulkMode)
</body>
</html>
@endif
