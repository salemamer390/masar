@props([
    'name',
    'size' => null,
    'color' => null,
    'strokeWidth' => 2,
])
@php($style = trim(($size ? '--ui-icon-size: '.(is_numeric($size) ? $size.'px' : $size).';' : '').($color ? '--ui-icon-color: '.$color.';' : '')))
<i data-lucide="{{ $name }}" class="ui-icon {{ $attributes->get('class') }}" @if($style) style="{{ $style }}" @endif width="{{ $size ?: 20 }}" height="{{ $size ?: 20 }}" stroke-width="{{ $strokeWidth }}" aria-hidden="true"></i>
