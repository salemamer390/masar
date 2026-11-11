@auth
<link rel="stylesheet" href="{{ asset('tour-editor.css') }}?v={{ @filemtime(public_path('tour-editor.css')) }}">
@php
  $tourUser = auth()->user();
  $tourTracked = \App\Support\GuidedTour::isTracked();
  $ownerViewer = \App\Support\OwnerGate::allows(auth()->guard('owner')->user());
  // Owner preview (?tour_preview=1): shows the draft (or published) steps to
  // the owner only, without touching their real tour progress. Owner edit
  // mode (?tour_edit=1) adds the element picker bar instead.
  $tourPreview = $tourTracked && $ownerViewer && request()->boolean('tour_preview') && \App\Support\GuidedTour::tableReady();
  $tourEdit = $ownerViewer && request()->boolean('tour_edit') && \App\Support\GuidedTour::tableReady();
  $tourSteps = \App\Support\GuidedTour::stepsFor($tourUser, $tourPreview && \App\Support\GuidedTour::hasDraft());
  $tourLabels = [
    'back' => __('السابق'),
    'next' => __('التالي'),
    'finish' => __('إنهاء'),
    'dialog' => __('جولة تعريفية'),
    'goTo' => __('الانتقال إلى الصفحة'),
    'navHint' => __('اضغط على «:page» من القائمة الجانبية للدخول إلى هذه الصفحة.'),
    'navTitle' => __('أين أجد الصفحة؟'),
    'mandatory' => __('الجولة إجبارية، أكمل الخطوات لتبدأ استخدام النظام.'),
    'previewBadge' => __('معاينة'),
    'endPreview' => __('إنهاء المعاينة'),
  ];
@endphp
<script>
window.masarGuidedTour = {
  active: @json($tourPreview || ($tourTracked && is_null($tourUser->tour_seen_at))),
  preview: @json($tourPreview),
  current: @json($tourPreview ? request()->query('tour_step') : ($tourUser->tour_step ?? null)),
  completeUrl: @json(route('tour.complete')),
  progressUrl: @json(route('tour.progress')),
  labels: @json($tourLabels, JSON_UNESCAPED_UNICODE),
  steps: @json($tourSteps, JSON_UNESCAPED_UNICODE),
};
</script>
<script src="{{ asset('js/tour.js') }}?v={{ @filemtime(public_path('js/tour.js')) }}" defer></script>
@if($tourEdit)
@php
  $tourEditLabels = [
    'bar' => __('وضع اختيار عنصر الجولة: مرّر على أي زر ثم اضغط عليه لإضافة خطوة له.'),
    'picked' => __('العنصر المحدد:'),
    'titleAr' => __('عنوان الخطوة (عربي)'),
    'bodyAr' => __('الشرح (عربي)'),
    'titleEn' => __('العنوان (إنجليزي — اختياري)'),
    'bodyEn' => __('الشرح (إنجليزي — اختياري)'),
    'permission' => __('الصلاحية المطلوبة (اختياري)'),
    'noPermission' => __('بدون صلاحية — تظهر للجميع'),
    'save' => __('حفظ الخطوة في المسودة'),
    'cancel' => __('إلغاء'),
    'exit' => __('خروج من وضع الاختيار'),
    'saved' => __('حُفظت الخطوة في المسودة. أضف عنصرًا آخر أو افتح إدارة الجولة للنشر.'),
    'failed' => __('تعذر الحفظ، راجع البيانات وحاول مجددًا.'),
    'missing' => __('هذه الصفحة لا تسمح بإضافة خطوة (مسار غير معروف).'),
    'openEditor' => __('فتح إدارة الجولة'),
  ];
@endphp
<script>
window.masarTourEditor = {
  routeName: @json(request()->route()?->getName()),
  saveUrl: @json(route('owner.tour.steps.picked')),
  editorUrl: @json(route('owner.tour.index')),
  permissions: @json(\App\Models\Permission::orderBy('name')->pluck('name')),
  labels: @json($tourEditLabels, JSON_UNESCAPED_UNICODE),
};
</script>
<script src="{{ asset('js/tour-edit.js') }}?v={{ @filemtime(public_path('js/tour-edit.js')) }}" defer></script>
@endif
@endauth
