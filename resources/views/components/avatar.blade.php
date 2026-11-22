{{--
    Avatar de una persona: su foto o, si aún no tiene, un círculo con dos iniciales y un color que
    depende de su nombre (siempre el mismo para la misma persona).

    Uso:  <x-avatar :user="$user" :size="36" />
          <x-avatar :user="$user" :size="112" :decorative="false" />   (cuando el nombre NO está al lado)

    "decorative" (por defecto sí) oculta la imagen a los lectores de pantalla porque el nombre
    suele estar escrito junto al avatar. Las iniciales salen de User::initials(); la foto, de
    User::avatarUrl(). Los colores son oscuros a propósito: el texto blanco se lee bien sobre todos.
--}}
@props(['user', 'size' => 40, 'decorative' => true])

@php
    $palette = ['#0b5ed7', '#6f42c1', '#a61e4d', '#146c43', '#b45309', '#0a7285', '#5c3d99', '#374151'];
    $color = $palette[crc32(mb_strtolower($user->name ?? '')) % count($palette)];
    $url = $user->avatarUrl();
@endphp

@if ($url)
    <img src="{{ $url }}" width="{{ $size }}" height="{{ $size }}" loading="lazy" decoding="async"
        {{ $attributes->class(['rounded-circle', 'object-fit-cover', 'flex-shrink-0']) }}
        @if ($decorative) alt="" aria-hidden="true" @else alt="Foto de perfil de {{ $user->name }}" @endif>
@else
    <span
        {{ $attributes->class(['d-inline-flex', 'align-items-center', 'justify-content-center', 'rounded-circle', 'text-white', 'fw-semibold', 'flex-shrink-0', 'user-select-none']) }}
        style="width: {{ $size }}px; height: {{ $size }}px; background-color: {{ $color }}; font-size: {{ round($size * 0.4) }}px; line-height: 1;"
        @if ($decorative) aria-hidden="true" @else role="img" aria-label="Iniciales de {{ $user->name }}" @endif>{{ $user->initials() }}</span>
@endif
