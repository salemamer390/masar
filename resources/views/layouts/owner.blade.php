<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="icon" type="image/png" href="{{ asset('images/masar-hr-logo.png') }}">
  <title>{{ config('app.name', 'MASAR HR') }} — @yield('title', __('لوحة تحكم المالك'))</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet" integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
  <link href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) }}" rel="stylesheet">
  <style>
    body { background: #f1f5f9; font-family: 'Cairo', sans-serif; }
    .owner-topbar {
      background: #0e172a; color: #fff; padding: 10px 24px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      position: sticky; top: 0; z-index: 1020;
    }
    .owner-topbar .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; }
    .owner-topbar .brand img { height: 30px; }
    .owner-topbar .badge-private {
      background: rgba(255,255,255,.12); border-radius: 999px;
      padding: 3px 12px; font-size: .75rem; font-weight: 700;
    }
    .owner-topbar .owner-user { display: flex; align-items: center; gap: 10px; font-size: .88rem; }
    .owner-topbar .btn-logout {
      background: transparent; border: 1px solid rgba(255,255,255,.35); color: #fff;
      border-radius: 8px; padding: 4px 14px; font-size: .82rem; font-weight: 600;
    }
    .owner-topbar .btn-logout:hover { background: rgba(255,255,255,.12); }
    .owner-content { padding: 24px; min-width: 0; }
    @media(max-width:600px){.owner-topbar{flex-wrap:wrap;padding:10px 14px}.owner-topbar .owner-user{flex-wrap:wrap}.owner-content{padding:14px}}
  </style>
  @stack('styles')
@if(app()->getLocale() === 'en')<link href="{{ asset('ltr.css') }}" rel="stylesheet">@endif
@include('components.interface-i18n')
</head>
<body>
  <header class="owner-topbar">
    <div class="brand">
      <img src="{{ asset('images/masar-hr-logo.png') }}" alt="MASAR HR">
      <span>{{ __('مسار') }}</span>
      <span class="badge-private"><i class="bi bi-shield-lock-fill"></i> {{ __('وصول خاص') }}</span>
    </div>
    <div class="owner-user">
      <span><i class="bi bi-person-circle"></i> {{ auth()->guard('owner')->user()?->name }}</span>
      <form method="POST" action="{{ route('owner.logout') }}">
        @csrf
        <button type="submit" class="btn-logout"><i class="bi bi-box-arrow-left"></i> {{ __('خروج') }}</button>
      </form>
    </div>
  </header>

  <main class="owner-content">
    <nav aria-label="{{ __('اللغة') }}"><a href="{{ route('locale.switch','ar') }}">العربية</a> | <a href="{{ route('locale.switch','en') }}">English</a></nav>
@yield('content')
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
  @if(session('toast'))
    <div class="toast-container position-fixed bottom-0 start-0 p-3">
      <div class="toast show align-items-center text-bg-dark border-0" role="status">
        <div class="d-flex"><div class="toast-body">{{ session('toast') }}</div></div>
      </div>
    </div>
  @endif
  @stack('scripts')
</body>
</html>
