<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name', 'Tazamun HR') }} — @yield('title', 'إدارة الموارد البشرية')</title>
  
  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  
  <!-- Prototype Stylesheet -->
  <link href="{{ asset('styles.css') }}" rel="stylesheet">
  <link href="{{ asset('ux.css') }}" rel="stylesheet">
  
  @stack('styles')
</head>
<body>
<div class="app-shell">
  @include('layouts.sidebar')

  <main class="main">
    @include('layouts.topbar')

    <section class="content">
      @php
        $persistentAlertMessage = $errors->any() ? $errors->first() : (session('error') ?? session('warning') ?? session('toast'));
        $persistentAlertType = $errors->any() || session('error') ? 'danger' : (session('warning') ? 'warning' : 'success');
      @endphp
      @if($persistentAlertMessage)
        <div class="global-alert global-alert-{{ $persistentAlertType }}" role="alert">
          <i class="bi {{ $persistentAlertType === 'danger' ? 'bi-exclamation-triangle-fill' : ($persistentAlertType === 'warning' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill') }}"></i>
          <span>{{ $persistentAlertMessage }}</span>
          <button type="button" class="global-alert-close" aria-label="إغلاق" onclick="this.closest('.global-alert')?.remove()"><i class="bi bi-x-lg"></i></button>
        </div>
      @endif
      @yield('content')
    </section>
  </main>
</div>

<!-- Global Generic Action Modal (from prototype) -->
<div class="modal fade" id="genericActionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="genericActionTitle">إجراء</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">البيان</label>
        <input class="form-control" id="genericActionInput" placeholder="اكتب القيمة...">
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-primary" id="genericActionSubmit">حفظ</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div class="toast-container global-toast-container position-fixed p-3">
  <div id="liveToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-body d-flex align-items-center gap-2">
      <i class="bi bi-check-circle-fill text-success fs-5"></i>
      <span id="toastText"></span>
    </div>
  </div>
</div>

<!-- Core Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  // Global Toast function matching prototype
  function showToast(msg) {
    const textEl = document.getElementById('toastText');
    const toastEl = document.getElementById('liveToast');
    if (textEl && toastEl) {
      textEl.textContent = msg;
      const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2500 });
      toast.show();
    }
  }

  // Sidebar toggle
  document.getElementById('menuBtn')?.addEventListener('click', function() {
    document.getElementById('sidebar')?.classList.toggle('open');
  });

  // Dark mode toggle with persistence
  const themeBtn = document.getElementById('themeToggleBtn');
  if (localStorage.getItem('tazamun_theme') === 'dark') {
    document.body.classList.add('preview-dark');
  }

  themeBtn?.addEventListener('click', function() {
    document.body.classList.toggle('preview-dark');
    const isDark = document.body.classList.contains('preview-dark');
    localStorage.setItem('tazamun_theme', isDark ? 'dark' : 'light');
    showToast(isDark ? 'تم تفعيل الوضع الداكن' : 'تم تفعيل الوضع الفاتح');
  });

  // CSRF setup for Fetch API
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

  // Trigger flash toasts from Laravel session
  @if(session('toast'))
    document.addEventListener('DOMContentLoaded', function() {
      showToast(@json(session('toast')));
    });
  @endif

  @if($errors->any())
    document.addEventListener('DOMContentLoaded', function() {
      showToast(@json($errors->first()));
    });
  @endif
</script>

@stack('scripts')
</body>
</html>
