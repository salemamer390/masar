@php
  $notifications = auth()->user()?->unreadNotifications()->latest()->take(50)->get() ?? collect();
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
    <div class="dropdown"><div class="user-menu cursor-pointer" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer"><div class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 1)) }}</div><div class="user-text"><strong>{{ auth()->user()?->name ?? __('مستخدم') }}</strong><small>{{ __(auth()->user()?->role?->display_name ?? __('مدير النظام')) }}</small></div><i class="bi bi-chevron-down"></i></div><ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-2"><li class="px-3 py-2 border-bottom"><div class="fw-bold">{{ auth()->user()?->name }}</div><small class="text-muted">{{ auth()->user()?->email }}</small></li><li><form action="{{ route('logout') }}" method="POST" class="m-0 p-0">@csrf<button type="submit" class="dropdown-item text-danger py-2 d-flex align-items-center gap-2"><i class="bi bi-box-arrow-right"></i> {{ __('تسجيل الخروج') }}</button></form></li></ul></div>
    <div class="dropdown"><button class="icon-btn notification-trigger position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('الإشعارات') }}"><i class="bi bi-bell"></i>@if($unreadNotificationsCount > 0)<span class="notification-dot">{{ $unreadNotificationsCount }}</span>@endif</button><div class="dropdown-menu dropdown-menu-end notification-menu shadow border-0 p-0 mt-2"><div class="px-3 py-2 border-bottom d-flex justify-content-between"><b>{{ __('الإشعارات') }}</b><small class="text-muted">{{ $unreadNotificationsCount }} {{ __('جديدة') }}</small></div>@if($unreadNotificationsCount > 0)<form method="POST" action="{{ route('notifications.read-all') }}" class="px-3 py-2 border-bottom">@csrf<button type="submit" class="btn btn-sm btn-link p-0">{{ __('تعيين الكل كمقروءة') }}</button></form>@endif<div class="notification-scroll" role="region" aria-label="{{ __('قائمة الإشعارات') }}" tabindex="0">@forelse($notifications as $notification)<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="dropdown-item notification-item" type="submit"><i class="bi bi-file-earmark-text text-warning"></i><span>{{ $notification->data['message'] ?? __('إشعار جديد') }}<small>{{ $notification->created_at?->diffForHumans() }}</small></span></button></form>@empty<div class="p-3 text-muted small">{{ __('لا توجد إشعارات جديدة.') }}</div>@endforelse</div><a class="dropdown-item notification-all-link text-center border-top small py-2" href="{{ route('notifications.index') }}">{{ __('عرض كل الإشعارات') }}</a></div></div>
    <div class="dropdown"><button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('تغيير اللغة') }}" title="{{ __('اللغة') }}"><i class="bi bi-translate"></i></button><ul class="dropdown-menu dropdown-menu-end language-menu shadow-sm border-0 rounded-3 mt-2"><li><a class="dropdown-item d-flex align-items-center gap-2 {{ app()->getLocale() === 'ar' ? 'active' : '' }}" href="{{ route('locale.switch', ['locale' => 'ar']) }}">العربية</a></li><li><a class="dropdown-item d-flex align-items-center gap-2 {{ app()->getLocale() === 'en' ? 'active' : '' }}" href="{{ route('locale.switch', ['locale' => 'en']) }}">English</a></li></ul></div>
    <button class="icon-btn" id="themeToggleBtn" title="{{ __('الوضع الليلي / الفاتح') }}" aria-label="{{ __('تبديل المظهر') }}"><i class="bi bi-moon"></i></button>
    <button class="icon-btn cards-visibility-toggle" id="cardsVisibilityToggle" type="button" aria-pressed="true" aria-label="{{ __('إخفاء الكروت') }}" title="{{ __('إخفاء الكروت') }}"><i class="bi bi-eye-slash" aria-hidden="true"></i><span class="visually-hidden">{{ __('إخفاء الكروت') }}</span></button>
  </div>
  @if($notifications->isNotEmpty())
    @php($latestNotification = $notifications->first())
    <div class="notification-live-alert" id="notificationLiveAlert" data-notification-id="{{ $latestNotification->id }}" data-notification-url="{{ $latestNotification->data['url'] ?? route('notifications.index') }}" role="status" aria-live="polite">
      <div class="notification-live-icon"><i class="bi bi-bell-fill"></i></div>
      <div class="notification-live-content"><strong>{{ __('إشعار جديد') }}</strong><span>{{ $latestNotification->data['message'] ?? __('لديك إشعار جديد يحتاج إلى المراجعة.') }}</span><small>{{ $latestNotification->created_at?->diffForHumans() }}</small></div>
      <button type="button" class="notification-live-close" aria-label="{{ __('إغلاق') }}"><i class="bi bi-x-lg"></i></button>
    </div>
  @endif
</header>
