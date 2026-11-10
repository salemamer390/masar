@extends('layouts.app')
@section('title',__('إضافة جهاز بصمة'))
@section('content')<div class="page-head"><h1>{{ __('إضافة جهاز بصمة') }}</h1></div><form method="POST" action="{{ route('fingerprint.devices.store') }}">@csrf @include('fingerprint.devices._form')<button class="btn btn-primary">{{ __('حفظ الجهاز') }}</button> <a class="btn btn-light" href="{{ route('fingerprint.devices.index') }}">{{ __('إلغاء') }}</a></form>@endsection
