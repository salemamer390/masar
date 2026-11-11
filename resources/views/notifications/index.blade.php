@extends('layouts.app')
@section('title', __('الإشعارات'))
@section('content')
<div class="page-head"><div><h1>{{ __('الإشعارات') }}</h1><p>{{ __('متابعة التنبيهات المهمة المرتبطة بالموظفين والرواتب والمستندات والإجازات.') }}</p></div><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-outline-primary" {{ auth()->user()->unreadNotifications()->exists() ? '' : 'disabled' }}><i class="bi bi-check2-all"></i> {{ __('تعليم الكل كمقروء') }}</button></form></div>
<div class="panel table-panel"><div class="notification-center-list">@forelse($notifications as $notification)@php($kind = $notification->data['kind'] ?? 'general')<div class="notification-center-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}"><div class="notification-center-icon"><i class="bi {{ match($kind) { 'contract_expiry' => 'bi-file-earmark-text', 'document_expiry' => 'bi-folder2-open', 'holiday_reminder' => 'bi-calendar-event', 'leave' => 'bi-calendar2-check', 'hr_request' => 'bi-inbox', 'payroll' => 'bi-cash-stack', default => 'bi-bell' } }}"></i></div><div class="notification-center-content"><div class="d-flex justify-content-between gap-2"><strong>{{ $notification->data['message'] ?? __('إشعار جديد') }}</strong>@if(!$notification->read_at)<span class="badge-soft warning">{{ __('جديد') }}</span>@endif</div><small>{{ $notification->created_at?->diffForHumans() }}</small>@if(!empty($notification->data['url']))<form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="mt-2">@csrf<button class="btn btn-sm btn-link p-0">{{ __('فتح التفاصيل') }} <i class="bi bi-arrow-left"></i></button></form>@endif</div></div>@empty<div class="text-center text-muted py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>{{ __('لا توجد إشعارات حتى الآن.') }}</div>@endforelse</div>@if($notifications->hasPages())<div class="p-3">{{ $notifications->links() }}</div>@endif</div>
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
