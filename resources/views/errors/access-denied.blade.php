@extends('layouts.app')

@section('title', __('لا توجد صلاحيات متاحة'))

@section('content')
<div class="panel text-center py-5">
  <i class="bi bi-shield-lock text-warning" style="font-size:3rem"></i>
  <h1 class="h4 mt-3">{{ __('لا توجد صفحات متاحة لهذا الحساب حاليًا') }}</h1>
  <p class="text-muted mb-4">{{ __('يرجى التواصل مع مدير الشركة لمنحك الصلاحيات المناسبة.') }}</p>
  <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-secondary">{{ __('تسجيل الخروج') }}</button></form>
</div>
@endsection
