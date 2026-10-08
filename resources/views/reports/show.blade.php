@extends('layouts.app')
@section('title',$title)
@section('content')
<div class="page-head"><div><h1>{{$title}}</h1><p>{{$subtitle}}</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('reports.print'))<button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>@endif @if(auth()->user()?->hasPermission('reports.export_excel'))<a class="btn btn-primary" href="{{route('reports.export',$type)}}"><i class="bi bi-file-earmark-excel"></i> Excel</a>@endif</div></div>
<div class="panel table-panel"><table class="table align-middle"><thead><tr>@foreach($columns as $c)<th>{{$c}}</th>@endforeach</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach($row as $cell)<td>{!! e($cell) !!}</td>@endforeach</tr>@empty<tr><td colspan="{{count($columns)}}" class="text-center py-5">لا توجد بيانات لهذا التقرير.</td></tr>@endforelse</tbody></table></div>
@endsection
