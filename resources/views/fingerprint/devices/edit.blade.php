@extends('layouts.app')
@section('title',__('تعديل جهاز بصمة'))
@section('content')<form method="POST" action="{{ route('fingerprint.devices.update',$device) }}">@csrf @method('PUT') @include('fingerprint.devices._form')<button class="btn btn-primary">{{ __('حفظ التعديل') }}</button> <a class="btn btn-light" href="{{ route('fingerprint.devices.show',$device) }}">{{ __('إلغاء') }}</a></form>@endsection
