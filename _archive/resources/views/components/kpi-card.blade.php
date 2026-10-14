@props([
    'title',
    'value',
    'hint' => null,
    'theme' => 'employees',
    'url' => null,
    'modal' => null,
    'class' => '',
])
@php($ui = ui_theme($theme))
@php($tag = $url ? 'a' : 'div')
<{{ $tag }} @if($url) href="{{ $url }}" @endif @if($modal) data-bs-toggle="modal" data-bs-target="#{{ $modal }}" @endif class="ui-kpi-card {{ $ui['tone'] }} {{ $class }}" {{ $attributes }}>
    <span class="ui-kpi-icon"><x-icon :name="$ui['icon']" /></span>
    <span class="ui-kpi-content"><b>{{ $title }}</b><strong>{{ $value }}</strong>@if($hint)<small>{{ $hint }}</small>@endif</span>
</{{ $tag }}>
