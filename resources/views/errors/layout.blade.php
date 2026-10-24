<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') - @yield('title')</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
    <div class="text-center p-4">
        <div class="display-1 fw-bold text-primary">@yield('code')</div>
        <h1 class="h4 mb-2">@yield('title')</h1>
        <p class="text-muted mb-4">@yield('message')</p>
        <a href="{{ url('/') }}" class="btn btn-primary">
            <i class="bi bi-house me-1"></i> Volver al inicio
        </a>
    </div>
</body>

</html>

