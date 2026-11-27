<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl() ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('الحساب مغلق مؤقتًا') }} | Odir HR</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}?v=3">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7faff;font-family:"Segoe UI",Tahoma,Arial,sans-serif;color:#1d3154;padding:18px}
.box{max-width:480px;width:100%;background:#fff;border:1px solid #e4ebf5;border-radius:18px;padding:30px 26px;text-align:center;box-shadow:0 18px 50px #173b7114}
.box img{width:132px;height:auto}.icon{font-size:34px;margin:14px 0 6px}h1{font-size:21px;line-height:1.7;color:#10264d;margin:0 0 10px}p{font-size:14.5px;line-height:2.1;color:#53637f;margin:0 0 10px}
.mail{direction:ltr;display:inline-block;background:#eef4ff;border-radius:9px;padding:4px 12px;font-weight:700;color:#10264d;font-size:14px}
.btn{display:block;width:100%;border:0;border-radius:12px;padding:14px;font:inherit;font-weight:800;font-size:15px;cursor:pointer;margin-top:16px;background:#1677f9;color:#fff;min-height:46px}.btn.light{background:#fff;color:#10264d;border:1px solid #e4ebf5;margin-top:10px}
.ok{background:#e6f8f1;color:#0a7a58;border-radius:10px;padding:10px 12px;font-size:13.5px;margin:12px 0}.err{background:#fdecec;color:#b42318;border-radius:10px;padding:10px 12px;font-size:13.5px;margin:12px 0}
.note{font-size:12.5px;color:#7a89a2}
</style></head>
<body><div class="box">
<img src="{{ asset('images/odir-hr-wordmark.png') }}" alt="Odir HR">
<div class="icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f5a524" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
<h1>{{ __('تم إغلاق الحساب مؤقتًا حتى تأكيد البريد الإلكتروني') }}</h1>
<p>{{ __('مرّت 7 أيام ولم نستلم تأكيد بريدك الإلكتروني.') }}</p>
<p><strong>{{ __('بياناتك كلها محفوظة ولم يُحذف شيء.') }}</strong> {{ __('بمجرد الضغط على رابط التأكيد يعود حسابك للعمل فورًا.') }}</p>
<p class="mail">{{ auth()->user()->email }}</p>
@if(session('toast'))<div class="ok">{{ session('toast') }}</div>@endif
@if(session('error'))<div class="err">{{ session('error') }}</div>@endif
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="btn">{{ __('إرسال رابط التأكيد من جديد') }}</button></form>
<p class="note" style="margin-top:14px">{{ __('افتح الرسالة من بريدك واضغط على الرابط. إذا لم تجدها، افحص مجلد الرسائل غير المرغوبة (Spam) أو الترويجات.') }}</p>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="btn light">{{ __('تسجيل الخروج') }}</button></form>
</div></body></html>
