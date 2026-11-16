@php($modalId = $modalId ?? 'penaltyModal')
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ route('discipline.store') }}">@csrf
      <div class="modal-header"><h5 class="modal-title" id="{{ $modalId }}Title">{{ __('تسجيل جزاء أو مخالفة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('إغلاق') }}"></button></div>
      <div class="modal-body">@include('discipline.partials.entry-fields', ['prefix' => $modalId])</div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ') }}</button></div>
    </form>
  </div></div>
</div>
