@extends('layouts.app')
@section('title', __('الإشعارات'))
@section('content')
<div class="page-head"><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-outline-primary" {{ auth()->user()->unreadNotifications()->exists() ? '' : 'disabled' }}><i class="bi bi-check2-all"></i> {{ __('تعليم الكل كمقروء') }}</button></form></div>
<div class="panel table-panel"><div class="notification-center-list">@forelse($notifications as $notification)@php($kind = $notification->data['kind'] ?? 'general')<div class="notification-center-item {{ $notification->read_at ? 'is-read' : 'is-unread' }} {{ $kind === 'insurance_deregistration' ? 'notice-'.($notification->data['severity'] ?? 'warning') : '' }}" @if(!empty($notification->data['url'])) data-open-notification="{{ $notification->id }}" style="cursor:pointer" @endif><div class="notification-center-icon"><i class="bi {{ match($kind) { 'contract_expiry' => 'bi-file-earmark-text', 'document_expiry' => 'bi-folder2-open', 'holiday_reminder' => 'bi-calendar-event', 'leave' => 'bi-calendar2-check', 'leave_attendance_conflict' => 'bi-exclamation-triangle', 'insurance_deregistration' => 'bi-exclamation-octagon-fill', 'hr_request' => 'bi-inbox', 'payroll' => 'bi-cash-stack', default => 'bi-bell' } }}"></i></div><div class="notification-center-content"><div class="d-flex justify-content-between gap-2"><strong>{{ $notification->data['message'] ?? __('إشعار جديد') }}</strong>@if(!$notification->read_at)<span class="badge-soft warning">{{ __('جديد') }}</span>@endif</div><small>{{ $notification->created_at?->diffForHumans() }}</small>@if(($notification->data['kind'] ?? '') === 'leave_attendance_conflict')<div class="mt-2 d-flex gap-3 align-items-center">@if(empty($notification->data['confirmed_at']))<form method="POST" action="{{ route('notifications.confirm', $notification->id) }}">@csrf<button class="btn btn-sm btn-primary" data-conflict-confirm><i class="bi bi-check2"></i> {{ __('تأكيد') }}</button></form>@else<span class="badge-soft success">{{ __('تم التأكيد') }}</span>@endif</div>@endif @include('notifications._deregister-button', ['notification' => $notification]) @if(!empty($notification->data['url']))<form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="mt-2">@csrf<button class="btn btn-sm btn-link p-0">{{ __('فتح التفاصيل') }} <i class="bi bi-arrow-left"></i></button></form>@endif</div></div>@empty<div class="text-center text-muted py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>{{ __('لا توجد إشعارات حتى الآن.') }}</div>@endforelse</div>@if($notifications->hasPages())<div class="p-3">{{ $notifications->links() }}</div>@endif</div>
<div class="notification-pagination-controls">
  <form method="GET" action="{{ url()->current() }}" class="d-flex align-items-center gap-2">
    @foreach(request()->except(['page', 'per_page']) as $key => $value) @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
    <label class="small text-muted mb-0">{{ __('عدد الصفوف') }}</label>
    <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="{{ __('عدد الصفوف في الصفحة') }}">
      @foreach([25,50,100,200] as $size)<option value="{{ $size }}" @selected((int) request('per_page',25)===$size)>{{ $size }}</option>@endforeach
    </select>
  </form>
</div>
@endsection
@push('styles')<style>.notification-pagination-controls{display:flex;justify-content:flex-end;padding:0 1rem 1rem}</style>@endpush
@push('styles')<style>.notification-center-list{display:flex;flex-direction:column}.notification-center-item{display:flex;gap:14px;padding:18px;border-bottom:1px solid var(--border);transition:.2s}.notification-center-item:last-child{border-bottom:0}.notification-center-item.is-unread{background:#f7faff}.notification-center-icon{width:42px;height:42px;border-radius:12px;background:#eaf2ff;color:#1769e0;display:grid;place-items:center;font-size:19px;flex:0 0 42px}.notification-center-content{min-width:0;flex:1}.notification-center-content strong{font-size:13px}.notification-center-content small{display:block;color:var(--muted);margin-top:5px}</style>@endpush
@push('scripts')<script>
(() => {
  // Clicking the notice itself opens its target (same as «فتح التفاصيل»); the confirm button keeps its own action.
  document.querySelectorAll('[data-open-notification]').forEach((item) => item.addEventListener('click', (event) => {
    if (event.target.closest('a,button,form,input')) return;
    item.querySelector('form[action$="/read"]')?.submit();
  }));
})();
(() => {
  const form = document.querySelector('form[action$="/notifications/read-all"]');
  if (form) form.dataset.ajax = 'true';
  document.addEventListener('masar:success', (event) => {
    if (event.target !== form) return;
    document.querySelectorAll('.notification-center-item.is-unread').forEach(item => {
      item.classList.remove('is-unread');
      item.classList.add('is-read');
      item.querySelector('.badge-soft')?.remove();
    });
    form?.querySelector('button[type="submit"]')?.setAttribute('disabled', 'disabled');
    document.querySelector('.notification-dot')?.remove();
  });
})();
</script>@endpush

@push('styles')<style>.notification-center-item.notice-warning{background:#fff8e1!important;border-inline-start:5px solid #f0ad00}.notification-center-item.notice-warning .notification-center-icon{background:#ffe8a3;color:#7a5200}.notification-center-item.notice-danger{background:#fdecea!important;border-inline-start:5px solid #d92d20}.notification-center-item.notice-danger .notification-center-icon{background:#f8c9c4;color:#a31510}</style>@endpush
