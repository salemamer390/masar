@php
  $notifications = auth()->user()?->notifications()->latest()->take(15)->get() ?? collect();
  $unreadNotifications = $notifications->whereNull('read_at');
  $unreadNotificationsCount = auth()->user()?->unreadNotifications()->count() ?? 0;
  $uiLocale = app()->getLocale();
  $todayLabel = now()->locale($uiLocale)->translatedFormat($uiLocale === 'ar' ? 'l، j F Y' : 'l, j F Y');
@endphp

<header class="topbar topbar-reference">
  <div class="topbar-date" aria-label="{{ __('تاريخ اليوم') }}"><i class="bi bi-calendar3"></i><strong>{{ $todayLabel }}</strong></div>
  <div class="topbar-search-area">
    <button class="icon-btn d-lg-none" id="menuBtn" type="button" aria-label="{{ __('فتح القائمة') }}"><i class="bi bi-list"></i></button>
    <button class="icon-btn sidebar-hamburger d-none d-lg-inline-flex" id="sidebarToggleBtn" data-sidebar-toggle type="button" aria-label="{{ __('إخفاء القائمة الجانبية') }}" title="{{ __('إخفاء القائمة الجانبية') }}"><i class="bi bi-list"></i></button>
    @if(auth()->user()?->hasPermission('employees.view'))
      <div class="search-box global-search-reference"><i class="bi bi-search"></i><form action="{{ route('employees.index') }}" method="GET" class="w-100 m-0"><input name="search" placeholder="{{ __('ابحث عن موظف، طلب، مستند، تقرير ...') }}" id="globalSearch" value="{{ request('search') }}"></form></div>
    @endif
  </div>
  <div class="topbar-tools">
    <button class="icon-btn sidebar-hamburger topbar-sidebar-toggle" id="topbarSidebarBtn" data-sidebar-toggle type="button" aria-label="{{ __('فتح القائمة الجانبية') }}" title="{{ __('فتح القائمة الجانبية') }}"><i class="bi bi-list"></i></button>
    <div class="dropdown"><div class="user-menu cursor-pointer" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer"><div class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 1)) }}</div><div class="user-text"><strong>{{ auth()->user()?->name ?? __('مستخدم') }}</strong><small>{{ __(auth()->user()?->role?->display_name ?? __('مدير النظام')) }}</small></div><i class="bi bi-chevron-down"></i></div><ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-2"><li class="px-3 py-2 border-bottom"><div class="fw-bold">{{ auth()->user()?->name }}</div><small class="text-muted">{{ auth()->user()?->email }}</small></li><li><form action="{{ route('tour.replay') }}" method="POST" class="m-0 p-0">@csrf<button type="submit" class="dropdown-item py-2 d-flex align-items-center gap-2"><i class="bi bi-signpost-2"></i> {{ __('إعادة الجولة التعريفية') }}</button></form></li><li><form action="{{ route('logout') }}" method="POST" class="m-0 p-0">@csrf<button type="submit" class="dropdown-item text-danger py-2 d-flex align-items-center gap-2"><i class="bi bi-box-arrow-right"></i> {{ __('تسجيل الخروج') }}</button></form></li></ul></div>
    <div class="dropdown"><button class="icon-btn notification-trigger position-relative" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="{{ __('الإشعارات') }}"><i class="bi bi-bell"></i>@if($unreadNotificationsCount > 0)<span class="notification-dot">{{ $unreadNotificationsCount }}</span>@endif</button><div class="dropdown-menu dropdown-menu-end notification-menu shadow border-0 p-0 mt-0"><div class="px-3 py-2 border-bottom d-flex justify-content-between"><b>{{ __('الإشعارات') }}</b><small class="text-muted">{{ $unreadNotificationsCount }} {{ __('جديدة') }}</small></div>@if($unreadNotificationsCount > 0)<form method="POST" action="{{ route('notifications.read-all') }}" class="px-3 py-2 border-bottom" data-ajax="true" data-bell-read-all onclick="event.stopPropagation()">@csrf<button type="submit" class="btn btn-sm btn-link p-0">{{ __('تعيين الكل كمقروءة') }}</button></form>@endif<div class="notification-scroll" role="region" aria-label="{{ __('قائمة الإشعارات') }}" tabindex="0">@forelse($notifications as $notification)<div class="notif-row {{ !$notification->read_at && ($notification->data['kind'] ?? '') === 'insurance_deregistration' ? 'notice-'.($notification->data['severity'] ?? 'warning') : '' }}"><form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="dropdown-item notification-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}" type="submit"><i class="bi {{ ($notification->data['kind'] ?? '') === 'insurance_deregistration' ? (($notification->data['severity'] ?? '') === 'danger' ? 'bi-exclamation-octagon-fill text-danger' : 'bi-exclamation-triangle-fill text-warning') : ((($notification->data['kind'] ?? '') === 'leave_attendance_conflict') ? 'bi-exclamation-triangle text-danger' : 'bi-file-earmark-text text-warning') }}"></i><span>{{ $notification->data['message'] ?? __('إشعار جديد') }}<small>{{ $notification->created_at?->diffForHumans() }}</small></span></button></form>@if(($notification->data['kind'] ?? '') === 'leave_attendance_conflict' && empty($notification->data['confirmed_at']))<form method="POST" action="{{ route('notifications.confirm', $notification->id) }}" class="px-3 pb-2" data-ajax="true" data-notif-refresh>@csrf<button type="submit" class="btn btn-sm btn-primary" data-bell-conflict-confirm><i class="bi bi-check2"></i> {{ __('تأكيد') }}</button></form>@endif @include('notifications._deregister-button', ['notification' => $notification, 'compact' => true])</div> @empty<div class="p-3 text-muted small">{{ __('لا توجد إشعارات جديدة.') }}</div>@endforelse</div><a class="dropdown-item notification-all-link text-center border-top small py-2" href="{{ route('notifications.index') }}">{{ __('عرض كل الإشعارات') }}</a></div></div>
    <div class="dropdown"><button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('تغيير اللغة') }}" title="{{ __('اللغة') }}"><i class="bi bi-translate"></i></button><ul class="dropdown-menu dropdown-menu-end language-menu shadow-sm border-0 rounded-3 mt-2"><li><a class="dropdown-item d-flex align-items-center gap-2 {{ app()->getLocale() === 'ar' ? 'active' : '' }}" href="{{ route('locale.switch', ['locale' => 'ar']) }}">العربية</a></li><li><a class="dropdown-item d-flex align-items-center gap-2 {{ app()->getLocale() === 'en' ? 'active' : '' }}" href="{{ route('locale.switch', ['locale' => 'en']) }}">English</a></li></ul></div>
    <button class="icon-btn" id="themeToggleBtn" title="{{ __('الوضع الليلي / الفاتح') }}" aria-label="{{ __('تبديل المظهر') }}"><i class="bi bi-moon"></i></button>
    <button class="icon-btn cards-visibility-toggle" id="cardsVisibilityToggle" type="button" aria-pressed="true" aria-label="{{ __('إخفاء الكروت') }}" title="{{ __('إخفاء الكروت') }}"><i class="bi bi-eye-slash" aria-hidden="true"></i><span class="visually-hidden">{{ __('إخفاء الكروت') }}</span></button>
  </div>
  @if($unreadNotifications->isNotEmpty())
    @php($latestNotification = $unreadNotifications->first())
    <div class="notification-live-alert" id="notificationLiveAlert" data-notification-id="{{ $latestNotification->id }}" data-notification-url="{{ $latestNotification->data['url'] ?? route('notifications.index') }}" role="status" aria-live="polite">
      <div class="notification-live-icon"><i class="bi bi-bell-fill"></i></div>
      <div class="notification-live-content"><strong>{{ __('إشعار جديد') }}</strong><span>{{ $latestNotification->data['message'] ?? __('لديك إشعار جديد يحتاج إلى المراجعة.') }}</span><small>{{ $latestNotification->created_at?->diffForHumans() }}</small></div>
      <button type="button" class="notification-live-close" aria-label="{{ __('إغلاق') }}"><i class="bi bi-x-lg"></i></button>
    </div>
  @endif
</header>
<style>.notice-warning{background:#fff8e1;border-inline-start:4px solid #f0ad00}.notice-danger{background:#fdecea;border-inline-start:4px solid #d92d20}.notice-danger .notification-item span{color:#a31510;font-weight:700}.notice-warning .notification-item span{color:#7a5200;font-weight:600}.notice-danger .notification-item:hover{background:#f8d4d0}.notice-warning .notification-item:hover{background:#ffefb8}.notif-row{display:flex;align-items:center}.notif-row>form:first-child{flex:1;min-width:0}.notification-item.is-unread{background:#f3f8ff}.notification-item.is-unread span{font-weight:600}.notification-item.is-read{background:transparent}.notification-item.is-read span{font-weight:400;color:#6b7280}.notification-item.is-read i{opacity:.55}.notice-deregister-form{padding:0 .5rem;flex:none}.notice-deregister-form .btn{white-space:nowrap;padding:.15rem .5rem;font-size:.78rem}</style>
<script>
// Notification actions that stay on the page: after the server confirms, re-read this same page and swap only the bell and the list.
document.addEventListener('masar:success', async (event) => {
  const form = event.target;
  if (!form?.matches?.('form[data-notif-refresh]')) return;
  try {
    const response = await fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new Error('refresh failed');
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    ['.notification-menu', '.notification-trigger', '.notification-center-list'].forEach((selector) => {
      const current = document.querySelector(selector);
      const next = doc.querySelector(selector);
      if (current && next) current.innerHTML = next.innerHTML;
    });
  } catch (error) {
    location.reload();
  }
});
document.addEventListener('masar:success', (event) => {
  const form = event.target;
  if (!form?.matches?.('form[data-bell-read-all]')) return;
  const menu = form.closest('.notification-menu');
  menu?.querySelectorAll('.notification-item').forEach((item) => { item.classList.remove('is-unread'); item.classList.add('is-read'); });
  menu?.querySelectorAll('.notice-warning,.notice-danger').forEach((box) => box.classList.remove('notice-warning', 'notice-danger'));
  const count = menu?.querySelector('.border-bottom small.text-muted');
  if (count) count.textContent = '0 ' + @json(__('جديدة'));
  document.querySelectorAll('.notification-trigger .notification-dot').forEach((dot) => dot.remove());
  document.getElementById('notificationLiveAlert')?.remove();
  form.remove();
});
</script>
<script>
// Keep the bell panel attached right under the bell icon on desktop (the stylesheet pins it to the sidebar edge).
(() => {
  const trigger = document.querySelector('.notification-trigger');
  const menu = document.querySelector('.topbar .notification-menu');
  if (!trigger || !menu) return;
  const place = () => {
    if (window.innerWidth <= 800) return;
    const r = trigger.getBoundingClientRect();
    menu.style.setProperty('top', (r.bottom + 4) + 'px', 'important');
    menu.style.setProperty('right', Math.max(10, window.innerWidth - r.right - 8) + 'px', 'important');
  };
  trigger.addEventListener('shown.bs.dropdown', () => { place(); setTimeout(place, 30); });
  window.addEventListener('resize', () => { if (menu.classList.contains('show')) place(); });
})();
</script>
