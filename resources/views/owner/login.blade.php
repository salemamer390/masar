<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="{{ asset('images/masar-hr-logo.png') }}">
  <title>دخول المالك — MASAR HR</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #0e172a 0%, #17243e 100%);
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: 'Cairo', sans-serif;
      padding: 16px;
    }
    .owner-login-card {
      width: 100%;
      max-width: 420px;
      background: #fff;
      border-radius: 16px;
      padding: 32px 28px;
      box-shadow: 0 24px 60px rgba(0,0,0,.35);
    }
    .owner-login-card h1 { font-size: 1.35rem; font-weight: 800; }
    .owner-login-card .subtitle { color: #64748b; font-size: .9rem; }
    .owner-login-card .badge-private {
      display: inline-flex; align-items: center; gap: 6px;
      background: #0e172a; color: #fff; border-radius: 999px;
      padding: 4px 14px; font-size: .78rem; font-weight: 700;
    }
    .owner-login-card label { font-weight: 600; font-size: .88rem; }
    .owner-login-card .btn-login {
      background: #0e172a; border: none; font-weight: 700; padding: 10px;
    }
    .owner-login-card .btn-login:hover { background: #1e2c4d; }
  </style>
</head>
<body>
  <div class="owner-login-card">
    <div class="text-center mb-4">
      <span class="badge-private"><i class="bi bi-shield-lock-fill"></i> وصول خاص</span>
      <h1 class="mt-3">لوحة تحكم المالك</h1>
      <p class="subtitle mb-0">تسجيل الدخول بحساب المالك المخصص</p>
    </div>

    @if($errors->any())
      <div class="alert alert-danger py-2">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('owner.login.attempt') }}">
      @csrf
      <div class="mb-3">
        <label for="email" class="form-label">البريد الإلكتروني</label>
        <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      </div>
      <div class="mb-4">
        <label for="password" class="form-label">كلمة المرور</label>
        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-login w-100">دخول</button>
    </form>
  </div>
</body>
</html>
