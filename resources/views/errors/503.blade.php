@extends('errors.layout')

{{--
    Se recarga sola cada 30 segundos: cuando el sitio vuelve, la persona ve la página que estaba pidiendo, sin hacer nada.
    Si la petición era un envío de formulario (POST), recargarla la repetiría: se vuelve al inicio.
--}}
@php($retryUrl = request()->isMethodSafe() ? request()->fullUrl() : url('/'))

@section('code', '503')
@section('title', isset($maintenance) ? 'Sitio en mantenimiento' : 'Servicio no disponible')
@section('message', isset($maintenance)
    ? 'Estamos haciendo ajustes en el sistema. Esta página se actualizará sola y mostrará lo que buscabas en cuanto el sitio vuelva a estar disponible.'
    : 'El sistema no puede procesar tu solicitud en este momento. Intenta nuevamente en unos minutos.')
@section('head')
    <meta http-equiv="refresh" content="30;url={{ $retryUrl }}">
@endsection
@section('extra')
    <p class="mb-4">
        <a href="{{ $retryUrl }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-clockwise me-1"></i> Reintentar ahora
        </a>
    </p>
@endsection
