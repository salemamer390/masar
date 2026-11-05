@extends('layouts.app')
@section('title','أجهزة البصمة')
@section('content')
<div class="page-head"><div><h1>أجهزة البصمة</h1><p>إدارة أجهزة البصمة ومزامنة سجلات الحضور.</p></div><div class="d-flex gap-2"><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeviceModal"><i class="bi bi-plus-lg"></i> جهاز جديد</button><form method="POST" action="{{ route('fingerprint.devices.sync-all') }}">@csrf<button class="btn btn-success"><i class="bi bi-arrow-repeat"></i> مزامنة الكل</button></form><a class="btn btn-light" href="{{ route('fingerprint.sync-logs.index') }}">سجلات المزامنة</a></div></div>
<form method="GET" action="{{ route('fingerprint.devices.index') }}" class="filter-bar"><div class="search-table"><i class="bi bi-search"></i><input name="search" placeholder="بحث باسم الجهاز أو العنوان" value="{{ request('search') }}"></div><select name="status"><option value="">كل الحالات</option><option value="1" @selected(request('status')==='1')>نشط</option><option value="0" @selected(request('status')==='0')>غير نشط</option></select><button class="btn btn-outline-primary">بحث</button></form>
<div class="panel table-panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الجهاز</th><th>العنوان</th><th>المنفذ</th><th>الموقع</th><th>آخر مزامنة</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>@forelse($devices as $device)<tr><td><a href="{{ route('fingerprint.devices.show',$device) }}" class="fw-bold">{{ $device->name }}</a><small class="d-block text-muted">{{ $device->driver->value ?? $device->driver }}</small></td><td dir="ltr">{{ $device->ip_address }}</td><td>{{ $device->port }}</td><td>{{ $device->physical_location ?: '—' }}</td><td>{{ $device->last_sync_at?->format('d/m/Y H:i') ?: 'لم تتم' }}</td><td><span class="badge-soft">{{ $device->health_status }}</span></td><td><div class="d-flex gap-1"><form method="POST" action="{{ route('fingerprint.devices.test',$device) }}">@csrf<button class="btn btn-sm btn-outline-success" title="اختبار"><i class="bi bi-plug"></i></button></form><form method="POST" action="{{ route('fingerprint.devices.sync',$device) }}">@csrf<button class="btn btn-sm btn-outline-primary" title="مزامنة"><i class="bi bi-arrow-repeat"></i></button></form><a class="btn btn-sm btn-outline-secondary" href="{{ route('fingerprint.devices.edit',$device) }}"><i class="bi bi-pencil"></i></a></div></td></tr>@empty<tr><td colspan="7" class="text-center py-5">لا توجد أجهزة بصمة.</td></tr>@endforelse</tbody></table></div></div>
<x-pagination-controls :paginator="$devices" />

@php
  $deviceFormFields = ['name','device_type','driver','ip_address','port','communication_key','serial_number','physical_location','timezone','sync_interval_minutes','sync_enabled','is_active','notes'];
  $deviceFormHasErrors = $errors->hasAny($deviceFormFields);
@endphp
<!-- Add Device Modal -->
<div class="modal fade" id="addDeviceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">إضافة جهاز بصمة</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
      <form method="POST" action="{{ route('fingerprint.devices.store') }}">
        @csrf
        <div class="modal-body">
          @if($deviceFormHasErrors)
          <div class="alert alert-danger py-2 px-3 small mb-3">
            @foreach($deviceFormFields as $deviceFormField)
              @foreach($errors->get($deviceFormField) as $error)
                <div>{{ $error }}</div>
              @endforeach
            @endforeach
          </div>
          @endif
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">اسم الجهاز</label><input name="name" class="form-control" required value="{{ old('name') }}"></div>
            <div class="col-md-6"><label class="form-label">نوع الجهاز</label><input name="device_type" class="form-control" value="{{ old('device_type','zkteco') }}"></div>
            <div class="col-md-6"><label class="form-label">مشغل الجهاز</label><select name="driver" class="form-select">@foreach(\App\Enums\FingerprintDeviceDriver::cases() as $deviceDriver)<option value="{{ $deviceDriver->value }}" @selected(old('driver','zkteco_tcp')===$deviceDriver->value)>{{ $deviceDriver->label() }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">IP الجهاز</label><input name="ip_address" dir="ltr" class="form-control" required value="{{ old('ip_address') }}"></div>
            <div class="col-md-3"><label class="form-label">المنفذ</label><input type="number" name="port" class="form-control" required value="{{ old('port',4370) }}"></div>
            <div class="col-md-3"><label class="form-label">Comm Key</label><input type="number" name="communication_key" class="form-control" value="{{ old('communication_key') }}"></div>
            <div class="col-md-6"><label class="form-label">الرقم التسلسلي</label><input name="serial_number" class="form-control" value="{{ old('serial_number') }}"></div>
            <div class="col-md-6"><label class="form-label">الموقع</label><input name="physical_location" class="form-control" value="{{ old('physical_location') }}"></div>
            <div class="col-md-4"><label class="form-label">المنطقة الزمنية</label><select name="timezone" class="form-select">@foreach($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone','Africa/Cairo')===$tz)>{{ $tz }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">فاصل المزامنة بالدقائق</label><input type="number" min="1" name="sync_interval_minutes" class="form-control" value="{{ old('sync_interval_minutes',15) }}"></div>
            <div class="col-md-2"><label class="form-label">المزامنة</label><select name="sync_enabled" class="form-select"><option value="1" @selected(old('sync_enabled',true))>مفعلة</option><option value="0" @selected(!old('sync_enabled',true))>متوقفة</option></select></div>
            <div class="col-md-2"><label class="form-label">الحالة</label><select name="is_active" class="form-select"><option value="1" @selected(old('is_active',true))>نشط</option><option value="0" @selected(!old('is_active',true))>متوقف</option></select></div>
            <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> حفظ الجهاز</button></div>
      </form>
    </div>
  </div>
</div>
@if($deviceFormHasErrors)
<script>document.addEventListener('DOMContentLoaded',function(){new bootstrap.Modal(document.getElementById('addDeviceModal')).show();});</script>
@endif
@endsection
