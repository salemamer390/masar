<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="{{ asset('images/masar-hr-logo.png') }}">
  <link rel="shortcut icon" type="image/png" href="{{ asset('images/masar-hr-logo.png') }}">
  <title>تسجيل الدخول — MASAR HR</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
      <img src="{{ asset('images/masar-hr-logo.png') }}" alt="MASAR HR">
    </div>
    <h1 class="h4 fw-bold mb-1">مسار — MASAR HR</h1>
    <p class="text-muted small mb-0">نظام إدارة الموارد البشرية وشؤون العاملين</p>
  </div>

  @if($errors->any())
    <div class="alert alert-danger py-2 px-3 small rounded-3 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ $errors->first() }}
    </div>
  @endif

  <form action="{{ route('login.attempt') }}" method="POST">
    @csrf
    <div class="mb-3">
      <label class="form-label small fw-bold">البريد الإلكتروني</label>
      <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
        <input type="email" name="email" class="form-control border-start-0" placeholder="أدخل البريد الإلكتروني" value="{{ old('email') }}" required autofocus autocomplete="username">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label small fw-bold">كلمة المرور</label>
      <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
        <input type="password" name="password" class="form-control border-start-0" placeholder="أدخل كلمة المرور" required autocomplete="current-password">
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="remember" id="rememberMe" checked>
        <label class="form-check-label small text-muted" for="rememberMe">تذكرني</label>
      </div>
      <small class="text-muted">استخدم بيانات الحساب المخصصة لك</small>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold d-flex justify-content-center align-items-center gap-2">
      <i class="bi bi-box-arrow-in-left"></i> تسجيل الدخول
    </button>
  </form>

  <div class="mt-4 pt-3 border-top text-center">
    <small class="text-muted">نظام إدارة الموارد البشرية MASAR HR</small>
  </div>
</div>

</body>
</html>
