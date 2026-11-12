<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="icon" type="image/png" href="{{ asset('images/odir-hr-logo.png') }}">
  <link rel="shortcut icon" type="image/png" href="{{ asset('images/odir-hr-logo.png') }}">
  <title>ODIR Platform Admin — @yield('title', __('إدارة الشركات'))</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
  <link href="{{ asset('platform-admin.css') }}?v={{ @filemtime(public_path('platform-admin.css')) }}" rel="stylesheet">
  @stack('styles')
@if(app()->getLocale() === 'en')<link href="{{ asset('ltr.css') }}" rel="stylesheet">@endif
@include('components.interface-i18n')
</head>
<body class="platform-body">
  <nav aria-label="{{ __('اللغة') }}"><a href="{{ route('locale.switch','ar') }}">العربية</a> | <a href="{{ route('locale.switch','en') }}">English</a></nav>
@yield('content')
  <script>
    document.querySelectorAll('[data-platform-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.platformConfirm)) event.preventDefault();
    }));
  </script>
  @stack('scripts')
</body>
</html>
