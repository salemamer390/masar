{{-- resources/views/forms/s1.blade.php  —  نموذج رقم (1) طلب اشتراك مؤمن عليه (ورق فاضي، Legal) --}}
@php
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
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>س1 - {{ $d['name'] }}</title>
<style>
  /* ضع ملفات الخطوط في public/fonts */
  @font-face { font-family: 'Mudir MT';        src: url('{{ asset('fonts/MudirMT.ttf') }}') format('truetype'); }
  @font-face { font-family: 'PT Bold Heading'; src: url('{{ asset('fonts/PTBoldHeading.ttf') }}') format('truetype'); }
  @page { size: 8.5in 14in; margin: 0; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  html, body { margin: 0; padding: 0; color: #000; }
  body { font-family: 'Mudir MT', 'Cairo', 'Tahoma', sans-serif; font-size: 14pt; direction: rtl; background: #ddd; }
  .page { width: 8.5in; height: 14in; padding: .45in .5in; margin: 0 auto 12px; background: #fff; overflow: hidden; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  @media print { body { background: #fff; } .page { margin: 0; } }

  .h { font-family: 'PT Bold Heading', 'Mudir MT', sans-serif; }
  .hdr { display: flex; align-items: center; justify-content: space-between; border-bottom: 3pt double #000; padding-bottom: 4pt; }
  .hdr .c { text-align: center; } .hdr img { height: 54pt; }
  .hdr .org { font-size: 12pt; line-height: 1.5; } .hdr .org .v { display: inline-block; min-width: 130pt; }
  .hdr .no { font-size: 12pt; width: 160pt; text-align: left; }
  .title { text-align: center; font-size: 16pt; margin: 6pt 0 4pt; letter-spacing: 1pt; }

  .sec { display: flex; align-items: center; margin: 7pt 0 5pt; }
  .sec:before, .sec:after { content: ''; flex: 1; border-top: 2.5pt solid #000; }
  .sec span { border: 1.5pt solid #000; border-radius: 9pt; padding: 1pt 24pt; min-width: 150pt; text-align: center; font-size: 14pt; white-space: nowrap; }

  .r { display: flex; align-items: flex-end; min-height: 25pt; margin-bottom: 5pt; white-space: nowrap; }
  .l { flex: none; }  .l1 { flex: none; width: 130pt; }  .l + .v, .l + .bx, .l + .cb { margin-right: 4pt; }
  .v { flex: 1; border-bottom: 1px dotted #000; min-height: 18pt; padding: 0 4pt; text-align: center; overflow: hidden; font-size: 14pt; }
  .gap { flex: none; width: 14pt; }
  .bx { display: inline-flex; flex: none; direction: ltr; }
  .bx i { font-style: normal; width: 19pt; height: 22pt; border: 1px solid #000; margin-left: -1px; text-align: center; font-family: Arial, sans-serif; font-size: 13pt; line-height: 20pt; }
  .sl { flex: none; width: 12pt; text-align: center; font-family: Arial, sans-serif; }
  .cb { flex: none; display: inline-block; width: 19pt; height: 19pt; border: 1px solid #000; text-align: center; font-family: 'Segoe UI Symbol', Arial, sans-serif; font-size: 14pt; line-height: 18pt; margin-left: 4pt; }
  .num { flex: none; display: inline-block; width: 19pt; height: 21pt; border: 1px solid #000; text-align: center; font-family: 'Times New Roman', serif; line-height: 19pt; }
  .money { display: flex; flex-direction: column; flex: none; }
  .money small { border: 1px solid #000; border-bottom: 0; text-align: center; font-size: 8pt; line-height: 11pt; margin-left: -1px; }
  .note { font-size: 12pt; margin-top: 6pt; }

  /* صفحة الضهر */
  .txt { line-height: 24pt; text-align: justify; }
  .it { display: flex; line-height: 24pt; text-align: justify; } .it b { flex: none; width: 27pt; font-weight: normal; }
  .sub { padding-right: 27pt; }
  .sig { margin: 4pt 0 0 0; } .sig .p { margin-top: 16pt; font-size: 12pt; }
  .center { text-align: center; }
</style>
</head>
<body>

{{-- ======================= صفحة 1 (الوش) ======================= --}}
<div class="page">
  <div class="hdr h">
    <div class="org"><div>الهيئة القومية للتأمين الاجتماعي</div><div>مكتب : <span class="v" style="border-bottom:1px dotted #000">{{ $d['office'] }}</span></div></div>
    <div class="c"><img src="{{ asset('images/nosi-logo.jpg') }}" alt=""></div>
    <div class="no">نموذج رقم ( 1 )</div>
  </div>
  <div class="title h">طلــــب اشتــــراك مــؤمـــــن عليـــــه</div>

  <div class="r"><span class="l" style="width:46pt">الفئة :</span>
    <span class="num">1</span><span class="l" style="margin:0 4pt 0 0">عاملين لدى الغير</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 1) }}</span>
    <span style="width:34pt"></span>
    <span class="num">2</span><span class="l" style="margin:0 4pt 0 0">أصحاب أعمال لهم منشآت</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 2) }}</span>
  </div>
  <div class="r"><span class="l" style="width:46pt"></span>
    <span class="num">3</span><span class="l" style="margin:0 4pt 0 0">العاملين بالمخابز</span><span class="cb" style="margin-right:6pt">{{ $chk($d['category'], 3) }}</span>
  </div>

  <div class="sec"><span>بيانات مقدم الطلب</span></div>
  <div class="r"><span class="l1">مقدم الطلب :</span><span class="v" style="flex:1.2">{{ $d['applicant_name'] }}</span><span class="gap"></span><span class="l">صفة مقدم الطلب :</span><span class="v" style="font-size:{{ $fs($d['applicant_role']) }}">{{ $d['applicant_role'] }}</span></div>
  <div class="r"><span class="l1">رقم تأميني :</span>{!! $boxes($d['applicant_ins_no'], 9) !!}<span class="gap"></span><span class="l">رقم التليفون :</span><span class="v" dir="ltr">{{ $d['applicant_phone'] }}</span></div>
  <div class="r"><span class="l1">رقم قومي :</span>{!! $boxes($d['applicant_nid'], 14) !!}</div>

  <div class="sec"><span>بيانات المؤمن عليه</span></div>
  <div class="r"><span class="l1">الرقم التأميني :</span>{!! $boxes($d['ins_no'], 9) !!}<span class="gap"></span><span class="l">اسم المؤمن عليه :</span><span class="v" style="font-size:{{ $fs($d['name']) }}">{{ $d['name'] }}</span></div>
  <div class="r"><span class="l1">الرقم القومي :</span>{!! $boxes($d['nid'], 14) !!}<span class="gap"></span><span class="l">الجنسية :</span><span class="v">{{ $d['nationality'] }}</span></div>
  <div class="r"><span class="l1">المؤهل :</span><span class="v">{{ $d['qualification'] }}</span><span class="gap"></span><span class="l">المهنة :</span><span class="v" style="font-size:{{ $fs($d['job']) }}">{{ $d['job'] }}</span></div>
  <div class="r"><span class="l1">تاريخ بدء الاشتراك :</span>{!! $boxes($d['start_day'], 2) !!}<span class="sl">/</span>{!! $boxes($d['start_month'], 2) !!}<span class="sl">/</span>{!! $boxes($d['start_year'], 4) !!}<span class="gap"></span><span class="l">القطاع :</span><span class="v">{{ $d['sector'] }}</span></div>
  <div class="r"><span class="l1">كود الاشتراك :</span><span class="v">{{ $d['sub_code'] }}</span><span class="gap"></span><span class="l">نوع المدة :</span><span class="v">{{ $d['period_type'] }}</span></div>
  <div class="r" style="margin-top:2pt"><span class="l1">أجر /دخل الاشتراك :</span>
    <span class="money"><small>جنيــه</small>{!! $boxes($d['wage'], 6, true) !!}</span><span class="gap"></span>
    <span class="l">الأجر الشامل :</span>
    <span class="money" style="margin-right:4pt"><small>جنيــه</small>{!! $boxes($d['total_wage'], 6, true) !!}</span></div>
  <div class="r"><span class="l">بيانات العجز إن وجدت :</span><span class="l" style="margin-right:6pt">تاريخ بداية العجز :</span>
    {!! $boxes($d['dis_day'], 2) !!}<span class="sl">/</span>{!! $boxes($d['dis_month'], 2) !!}<span class="sl">/</span>{!! $boxes($d['dis_year'], 4) !!}
    <span class="gap"></span><span class="l">نسبة العجز :</span><span class="v">{{ $d['dis_percent'] }}</span><span class="l" style="margin-right:4pt">%</span></div>
  <div class="r"><span class="l">استيفاء الكشف الطبي الابتدائي :</span><span class="l" style="margin-right:8pt">نعم</span><span class="cb" style="margin-right:4pt">{{ $chk($d['medical'], 1) }}</span><span class="l" style="margin-right:10pt">لا</span><span class="cb" style="margin-right:4pt">{{ $chk($d['medical'], 0) }}</span></div>

  <div class="sec"><span>بيانات المنشأة</span></div>
  <div class="r"><span class="l1">نوع المنشأة :</span>
    <span class="l">نمطي</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 1) }}</span><span style="width:6pt"></span>
    <span class="l">سيارة</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 2) }}</span><span style="width:6pt"></span>
    <span class="l">مركب صيد</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 3) }}</span><span style="width:6pt"></span>
    <span class="l">مخابز بلدية</span><span class="cb" style="margin-right:4pt">{{ $chk($d['est_type'], 4) }}</span></div>
  <div class="r"><span class="l1">اسم المنشأة :</span><span class="v" style="font-size:{{ $fs($d['est_name']) }}">{{ $d['est_name'] }}</span><span class="gap"></span><span class="l">رقم المنشأة :</span>{!! $boxes($d['est_no'], 9, true) !!}</div>

  <div class="sec"><span>بيانات محل إقامة المؤمن عليه</span></div>
  <div class="r"><span class="l1">عقار رقم :</span><span class="v" style="flex:.6">{{ $d['addr_building'] }}</span><span class="gap"></span><span class="l">شارع :</span><span class="v" style="flex:1.6">{{ $d['addr_street'] }}</span><span class="gap"></span><span class="l">قرية :</span><span class="v">{{ $d['addr_village'] }}</span></div>
  <div class="r"><span class="l1">قسم / مركز :</span><span class="v">{{ $d['addr_district'] }}</span><span class="gap"></span><span class="l">محافظة :</span><span class="v">{{ $d['addr_governorate'] }}</span></div>

  <div class="sec"><span>التوقيع</span></div>
  <div class="r"><span class="l1">توقيع المؤمن عليه :</span><span class="v" style="flex:.9"></span><span class="gap" style="width:40pt"></span><span class="l" style="flex:1;text-align:center">توقيع صاحب العمل / المدير المسئول</span></div>
  <div class="r" style="min-height:30pt"><span class="l1">رقم التليفون :</span><span class="v" dir="ltr" style="flex:.9">{{ $d['phone'] }}</span><span class="gap" style="width:40pt"></span><span class="l">(</span><span class="v" style="flex:.8"></span><span class="l">)</span></div>
  <div class="r"><span class="l1">تحريراً في :</span><span class="l" dir="ltr" style="font-family:Arial">{{ $d['issue_date'] }}</span></div>

  <div class="sec"><span>مطابقة التوقيع</span></div>
  <div class="r"><span class="l">توقيع الموظف المختص بالمطابقة :</span><span class="v"></span><span class="gap"></span><span class="l">تاريخ المطابقة :</span><span class="l" style="font-family:Arial;margin-right:6pt">&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;/&nbsp;&nbsp;&nbsp;&nbsp;</span></div>
  <div class="note"><span class="h">ملحوظة :</span> على صاحب العمل والعامل الاطلاع على التوجيهات الموضحة خلف النموذج مع التوقيع على الإقرار. &nbsp;&nbsp;(انظر خلفه)</div>
</div>

{{-- ======================= صفحة 2 (الضهر) ======================= --}}
<div class="page">
  <div class="center h" style="font-size:14pt;margin-bottom:2pt">إرشـــــــــــادات</div>
  <div class="it"><b>١.</b><span>على صاحب العمل أن يرسل هذا النموذج مع طلب اشتراكه في الهيئة لأول مرة وخلال أسبوعين على الأكثر من تاريخ التحاق أي عامل جديد بالعمل لديه سواء كان التحاقاً نهائياً أو تحت الاختبار.</span></div>
  <div class="it"><b>٢.</b><span>التوقيع على هذا النموذج بما يفيد الاطلاع والموافقة على جميع البيانات الواردة به ولا يجوز لمـــــن وقَّع عليه أن يعارض في تلك البيانات أمام الهيئة وله أن يلجأ إلى مكتب علاقات العمل المختص أو القضاء.</span></div>
  <div class="it"><b>٣.</b><span>يستخدم هذا النموذج كطلب اشتراك في تأمين إصابات العمل فقط بالنسبة للفئات التالية:</span></div>
  <div class="it sub"><b>(أ)</b><span>من تجاوز سن الشيخوخة وأوقف انتفاعه بتأمين الشيخوخة والعجز والوفاة.</span></div>
  <div class="it sub"><b>(ب)</b><span>العاملون الذين يخضعون لأحكام قانون العمل ممن تقل أعمارهم عن ١٨ سنة.</span></div>
  <div class="it sub"><b>(ج)</b><span>العاملون المتدرجون والتلاميذ الصناعيون والطلاب المشتغلون في مشروعات التشغيل الصيفـــي والخدمة العامة، ويشترط اعتماد النموذج المحرر لهم من المدير المسئول بالهيئة التي تشرف على التلمذة الصناعية، والتدريب مع ختمها بخاتم هذه الجهة مع إرفاق نســـخة من عقد عمل المتدرب أو المســـتند المثبت لنوع العمل في جميع هذه الحالات.</span></div>
  <div class="it sub"><b>(د)</b><span>يقتصر استيفاء الأجر على الفئات التي يتقاضى فيها المؤمن عليه أجراً من صاحب العمل.</span></div>

  <div class="center h" style="font-size:14pt;margin:16pt 0 2pt">إقـــــــــــرار</div>
  <div class="r"><span class="l" style="width:92pt">اســـم المنشأة :</span><span class="v" style="flex:1.6;font-size:{{ $fs($d['est_name']) }}">{{ $d['est_name'] }}</span><span class="gap"></span><span class="l">رقمها التأميني :</span><span class="v">{{ $d['est_no'] }}</span></div>
  <div class="r"><span class="l" style="width:92pt">العنـــوان :</span><span class="v">{{ $d['est_address'] }}</span></div>
  <div class="txt" style="margin-top:4pt">أقر أنا الموقع على هذا النموذج بالالتزام بعرض المؤمن عليه على اللجنة الطبية المختصـــة بالهيئة المعنية بالتأمين الصحي أو الجهة الطبية المختصـــة لإجراء الفحص الطبي الأولي وإثبات حالته الصـــحية وقت توقيع الكشـــف الطبي مع الالتزام بموافاة الهيئة بتقرير اللياقة الطبية الصـــادر من الجهة الطبية المختصـــة عن حالته الصـــحية خلال أسبوعين على الأكثر من تاريخ التحاقه بالعمل تطبيقاً لأحكام قانون العمل.</div>
  <div class="sig"><div class="h" style="font-size:14pt;margin-top:6pt">توقيع (صاحب العمل / المدير المسئول)</div><div class="p">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div></div>

  <div class="txt" style="margin-top:20pt">أقر أنا <span style="border-bottom:1px dotted #000;padding:0 14pt">{{ $d['name'] }}</span> العامل بالمنشـــأة عاليه بأن أثبت حالتي الصـــحية أمام اللجنة الطبية المختصـــة بالهيئة المعنية بالتأمين الصـــحي أو الجهة الطبية المختصـــة وموافاة الهيئة بالتقرير الطبي عن حالتي الصـــحية خلال أسبوعين من تاريخ التحاقي بالعمل وفي حالة عدم قيامي بذلك فإن الهيئة القومية للتأمين الاجتماعي ليس عليها أدنى التزام قانوني بعرضي على اللجان الطبية لإثبات العجز أو صرف أية مستحقات تأمينية تترتب على العجز أياً كان نوعه السابق أو المعاصر لتاريخ الالتحاق بالعمل.</div>
  <div class="sig"><div class="h" style="font-size:14pt;margin-top:6pt;padding-right:40pt">توقيع المؤمن عليه</div><div class="p" style="padding-right:10pt">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div></div>
</div>

@if(request('print'))
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
@endif
</body>
</html>
