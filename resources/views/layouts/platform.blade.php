<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>MASAR Platform Admin — @yield('title', 'إدارة الشركات')</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="{{ asset('platform-admin.css') }}?v={{ @filemtime(public_path('platform-admin.css')) }}" rel="stylesheet">
  @stack('styles')
</head>
<body class="platform-body">
  @yield('content')
  <script>
    document.querySelectorAll('[data-platform-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.platformConfirm)) event.preventDefault();
    }));
  </script>
  @stack('scripts')
</body>
</html>
