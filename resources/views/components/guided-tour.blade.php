@auth
@php
  $tourUser = auth()->user();
  $tourTracked = \App\Support\GuidedTour::isTracked();
  $tourSteps = \App\Support\GuidedTour::stepsFor($tourUser);
  $tourLabels = [
    'back' => __('السابق'),
    'next' => __('التالي'),
    'finish' => __('إنهاء'),
    'dialog' => __('جولة تعريفية'),
    'goTo' => __('الانتقال إلى الصفحة'),
    'navHint' => __('اضغط على «:page» من القائمة الجانبية للدخول إلى هذه الصفحة.'),
    'navTitle' => __('أين أجد الصفحة؟'),
    'mandatory' => __('الجولة إجبارية، أكمل الخطوات لتبدأ استخدام النظام.'),
  ];
@endphp
<script>
window.masarGuidedTour = {
  active: @json($tourTracked && is_null($tourUser->tour_seen_at)),
  current: @json($tourUser->tour_step ?? null),
  completeUrl: @json(route('tour.complete')),
  progressUrl: @json(route('tour.progress')),
  labels: @json($tourLabels, JSON_UNESCAPED_UNICODE),
  steps: @json($tourSteps, JSON_UNESCAPED_UNICODE),
};
</script>
<script src="{{ asset('js/tour.js') }}?v={{ @filemtime(public_path('js/tour.js')) }}" defer></script>
@endauth
