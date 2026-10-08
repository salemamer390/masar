@php
  $notifications = auth()->user()?->unreadNotifications()->latest()->take(6)->get() ?? collect();
  $todayLabel = now()->locale('ar')->translatedFormat('l، j F Y');
@endphp

<header class="topbar topbar-reference">
  <div class="topbar-date" aria-label="تاريخ اليوم"><i class="bi bi-calendar3"></i><strong>{{ $todayLabel }}</strong></div>
  <div class="topbar-search-area">
    <button class="icon-btn d-lg-none" id="menuBtn" aria-label="فتح القائمة"><i class="bi bi-list"></i></button>
    @if(auth()->user()?->hasPermission('employees.view'))
      <div class="search-box global-search-reference"><i class="bi bi-search"></i><form action="{{ route('employees.index') }}" method="GET" class="w-100 m-0"><input name="search" placeholder="ابحث عن موظف، طلب، مستند، تقرير ..." id="globalSearch" value="{{ request('search') }}"></form></div>
    @endif
  </div>
  <div class="topbar-tools">
    <div class="dropdown"><div class="user-menu cursor-pointer" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer"><div class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? 'U', 0, 1)) }}</div><div class="user-text"><strong>{{ auth()->user()?->name ?? 'مستخدم' }}</strong><small>{{ auth()->user()?->role?->display_name ?? 'مدير النظام' }}</small></div><i class="bi bi-chevron-down"></i></div><ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-2"><li class="px-3 py-2 border-bottom"><div class="fw-bold">{{ auth()->user()?->name }}</div><small class="text-muted">{{ auth()->user()?->email }}</small></li><li><form action="{{ route('logout') }}" method="POST" class="m-0 p-0">@csrf<button type="submit" class="dropdown-item text-danger py-2 d-flex align-items-center gap-2"><i class="bi bi-box-arrow-right"></i> تسجيل الخروج</button></form></li></ul></div>
    <div class="dropdown"><button class="icon-btn notification-trigger position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="الإشعارات"><i class="bi bi-bell"></i>@if($notifications->isNotEmpty())<span class="notification-dot">{{ $notifications->count() }}</span>@endif</button><div class="dropdown-menu dropdown-menu-end notification-menu shadow border-0 p-0 mt-2"><div class="px-3 py-2 border-bottom d-flex justify-content-between"><b>الإشعارات</b><small class="text-muted">{{ $notifications->count() }} جديدة</small></div>@forelse($notifications as $notification)<form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="dropdown-item notification-item" type="submit"><i class="bi bi-file-earmark-text text-warning"></i><span>{{ $notification->data['message'] ?? 'إشعار جديد' }}<small>{{ $notification->created_at?->diffForHumans() }}</small></span></button></form>@empty<div class="p-3 text-muted small">لا توجد إشعارات جديدة.</div>@endforelse</div></div>
    <button class="icon-btn" id="themeToggleBtn" title="الوضع الليلي / الفاتح" aria-label="تبديل المظهر"><i class="bi bi-moon"></i></button>
  </div>
</header>
