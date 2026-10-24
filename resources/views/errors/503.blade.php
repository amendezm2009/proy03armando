@extends('errors.layout')

@section('code', '503')
@section('title', 'Servicio no disponible')
@section('message', 'El sistema no puede procesar tu solicitud en este momento. Intenta nuevamente en unos minutos.')
@section('head')
    <meta http-equiv="refresh" content="30">
@endsection
