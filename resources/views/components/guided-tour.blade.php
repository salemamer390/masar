@auth
@php
  $tourUser = auth()->user();
  $tourSteps = \App\Support\GuidedTour::stepsFor($tourUser);
  $tourLabels = [
    'skip' => __('تخطي الجولة'),
    'back' => __('السابق'),
    'next' => __('التالي'),
    'finish' => __('إنهاء'),
    'dialog' => __('جولة تعريفية'),
    'resumeTitle' => __('جولتك التعريفية ما زالت مستمرة'),
    'resume' => __('متابعة الجولة'),
  ];
@endphp
<script>
window.masarGuidedTour = {
  active: @json(is_null($tourUser->tour_seen_at)),
  current: @json($tourUser->tour_step),
  completeUrl: @json(route('tour.complete')),
  progressUrl: @json(route('tour.progress')),
  labels: @json($tourLabels, JSON_UNESCAPED_UNICODE),
  steps: @json($tourSteps, JSON_UNESCAPED_UNICODE),
};
</script>
<script src="{{ asset('js/tour.js') }}?v={{ @filemtime(public_path('js/tour.js')) }}" defer></script>
@endauth
