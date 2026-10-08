@extends('layouts.app')
@section('title','الأدوار والصلاحيات')
@section('content')
<div class="page-head"><div><h1>الأدوار والصلاحيات</h1><p>تحديد الصلاحيات لكل دور بالنظام.</p></div></div>
@foreach($roles as $role)<div class="panel mb-3"><div class="panel-head"><div><h3>{{$role->display_name}}</h3><span>{{$role->description}}</span></div></div><form method="POST" action="{{route('roles.update',$role)}}">@csrf @method('PUT')<div class="p-3 row g-3">@foreach($permissions as $module=>$items)<div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100"><b class="d-block mb-2">{{$module}}</b>@foreach($items as $p)<label class="d-flex align-items-center gap-2 mb-2 small"><input type="checkbox" name="permissions[]" value="{{$p->id}}" {{ $role->permissions->contains($p->id)?'checked':'' }} {{ $role->id===1 || !auth()->user()?->hasPermission('roles.edit') ? 'disabled' : '' }}> {{$p->display_name}}</label>@endforeach</div></div>@endforeach</div>@if($role->id!==1 && auth()->user()?->hasPermission('roles.edit'))<div class="p-3 border-top"><button class="btn btn-primary">حفظ الصلاحيات</button></div>@endif</form></div>@endforeach
@endsection
