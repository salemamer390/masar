@extends('layouts.app')
@section('title', __('تغيير كلمة المرور'))
@section('content')
<div class="panel" style="max-width:520px;margin-inline:auto;padding:24px">
  <h5 class="mb-1">{{ __('تغيير كلمة المرور') }}</h5>
  <p class="text-muted small mb-4">{{ __('اختر كلمة مرور من 8 أحرف على الأقل. سنرسل لك رسالة تأكيد على بريدك.') }}</p>
  <form method="POST" action="{{ route('account.password.update') }}" autocomplete="off">
    @csrf @method('PUT')
    <label class="form-label" for="current_password">{{ __('كلمة المرور الحالية') }}</label>
    <input id="current_password" name="current_password" type="password" class="form-control mb-3" required autocomplete="current-password">
    <label class="form-label" for="password">{{ __('كلمة المرور الجديدة') }}</label>
    <input id="password" name="password" type="password" class="form-control mb-3" minlength="8" required autocomplete="new-password">
    <label class="form-label" for="password_confirmation">{{ __('تأكيد كلمة المرور الجديدة') }}</label>
    <input id="password_confirmation" name="password_confirmation" type="password" class="form-control mb-4" minlength="8" required autocomplete="new-password">
    <button class="btn btn-primary w-100">{{ __('حفظ كلمة المرور') }}</button>
  </form>
</div>
@endsection
