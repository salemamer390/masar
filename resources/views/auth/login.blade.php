<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="{{ asset('images/odir-hr-logo.png') }}">
  <link rel="shortcut icon" type="image/png" href="{{ asset('images/odir-hr-logo.png') }}">
  <title>{{ __('تسجيل الدخول — ODIR HR') }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet" integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
  <link href="{{ asset('app.css') }}" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #0e172a 0%, #17243e 100%);
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 20px;
    }
    .login-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 40px 35px;
      width: 100%;
      max-width: 440px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
    }
    .login-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 30px;
      text-align: center;
    }
    .login-brand-icon {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: #1769e0;
      color: #fff;
      display: grid;
      place-items: center;
      font-size: 28px;
      margin-bottom: 12px;
      box-shadow: 0 8px 20px rgba(23, 105, 224, 0.3);
    }
    .login-brand-logo { background:#fff; padding:6px; overflow:hidden; }
    .login-brand-logo img { width:100%; height:100%; object-fit:contain; display:block; }
  </style>
</head>
<body>

<div class="login-card">
  <div class="login-brand">
    <div class="login-brand-icon login-brand-logo">
      <img src="{{ asset('images/odir-hr-logo.png') }}" alt="ODIR HR">
    </div>
    <h1 class="h4 fw-bold mb-1">{{ __('اودير — ODIR HR') }}</h1>
    <p class="text-muted small mb-0">{{ __('نظام إدارة الموارد البشرية وشؤون العاملين') }}</p>
  </div>

  @if($errors->any())
    <div class="alert alert-danger py-2 px-3 small rounded-3 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ $errors->first() }}
    </div>
  @endif

  <form action="{{ route('login.attempt') }}" method="POST">
    @csrf
    <div class="mb-3">
      <label class="form-label small fw-bold">{{ __('البريد الإلكتروني') }}</label>
      <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
        <input type="email" name="email" class="form-control border-start-0" placeholder="{{ __('أدخل البريد الإلكتروني') }}" value="{{ old('email') }}" required autofocus autocomplete="username">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label small fw-bold">{{ __('كلمة المرور') }}</label>
      <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
        <input type="password" name="password" class="form-control border-start-0" placeholder="{{ __('أدخل كلمة المرور') }}" required autocomplete="current-password">
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="remember" id="rememberMe" checked>
        <label class="form-check-label small text-muted" for="rememberMe">{{ __('تذكرني') }}</label>
      </div>
      <small class="text-muted">{{ __('استخدم بيانات الحساب المخصصة لك') }}</small>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold d-flex justify-content-center align-items-center gap-2">
      <i class="bi bi-box-arrow-in-left"></i> {{ __('تسجيل الدخول') }}
    </button>
  </form>

  <div class="text-center mt-3">
    <button type="button" class="btn btn-link text-decoration-none" data-bs-toggle="modal" data-bs-target="#companyRegistrationModal">
      <i class="bi bi-building-add me-1"></i> {{ __('تسجيل شركة جديدة') }}
    </button>
  </div>

  <div class="modal fade" id="companyRegistrationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form action="{{ route('register.company') }}" method="POST">
        @csrf
        <div class="modal-header"><h5 class="modal-title">{{ __('تسجيل شركة جديدة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('إغلاق') }}"></button></div>
        <div class="modal-body">
          <p class="text-muted small">{{ __('سيتم إنشاء حساب مدير الشركة وتفعيل باقة 20 موظف تلقائيًا.') }}</p>
          <div class="mb-3"><label class="form-label">{{ __('اسم الشركة') }}</label><input name="company_name" class="form-control" value="{{ old('company_name') }}" required></div>
          <div class="mb-3"><label class="form-label">{{ __('اسم مدير الشركة') }}</label><input name="owner_name" class="form-control" value="{{ old('owner_name') }}" required></div>
          <div class="mb-3"><label class="form-label">{{ __('البريد الإلكتروني') }}</label><input type="email" name="email" class="form-control" value="{{ old('email') }}" required autocomplete="email"></div>
          <div class="mb-3"><label class="form-label">{{ __('رقم الهاتف') }} <small class="text-muted">{{ __('(اختياري)') }}</small></label><input name="phone" class="form-control" value="{{ old('phone') }}"></div>
          <div class="row g-2"><div class="col-md-6"><label class="form-label">{{ __('كلمة المرور') }}</label><input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password"></div><div class="col-md-6"><label class="form-label">{{ __('تأكيد كلمة المرور') }}</label><input type="password" name="password_confirmation" class="form-control" required minlength="8" autocomplete="new-password"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i> {{ __('إنشاء الحساب') }}</button></div>
      </form>
    </div></div>
  </div>

  <div class="mt-4 pt-3 border-top text-center">
    <small class="text-muted">{{ __('نظام إدارة الموارد البشرية ODIR HR') }}</small>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
@if($errors->has('registration') || old('company_name'))
<script>document.addEventListener('DOMContentLoaded',function(){bootstrap.Modal.getOrCreateInstance(document.getElementById('companyRegistrationModal')).show();});</script>
@endif
<script>
window.addEventListener('pageshow', function (event) {
  if (!event.persisted) return;
  const modal = document.getElementById('companyRegistrationModal');
  if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).hide();
});
</script>
</body>
</html>
