<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Gestión de Productos') }} - @yield('title', 'Inicio')</title>
    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Scripts y Estilos Vite -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
    </style>
</head>

<body class="bg-light d-flex flex-column min-vh-100" data-authenticated="{{ auth()->check() ? 'true' : 'false' }}">
    <!-- Navbar Principal -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="{{ url('/') }}">
                <i class="bi bi-box-seam-fill text-primary fs-4"></i>
                <span>SysProducts</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
                aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    @auth
                        @can(App\PermissionName::ViewClassifications->value)
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('classifications.*') ? 'active fw-semibold' : '' }}"
                                    href="{{ route('classifications.index') }}">
                                    <i class="bi bi-tags me-1"></i> Clasificaciones
                                </a>
                            </li>
                        @endcan
                        @can(App\PermissionName::ViewUsers->value)
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('users.*') ? 'active fw-semibold' : '' }}"
                                    href="{{ route('users.index') }}">
                                    <i class="bi bi-people me-1"></i> Usuarios
                                </a>
                            </li>
                        @endcan
                        <li class="nav-item">
                            <a class="nav-link text-white-50" href="#" tabindex="-1" aria-disabled="true">
                                <i class="bi bi-boxes me-1"></i> Productos <span
                                    class="badge bg-secondary ms-1">Próx.</span>
                            </a>
                        </li>
                    @endauth
                </ul>

                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            @auth
                                <x-avatar :user="auth()->user()" :size="30" />
                            @else
                                <i class="bi bi-person-circle fs-5"></i>
                            @endauth
                            <span>{{ auth()->user()?->name ?? 'Invitado' }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" aria-labelledby="userMenu">
                            @auth
                                <li>
                                    <h6 class="dropdown-header">{{ auth()->user()->email }}</h6>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                        <i class="bi bi-person-gear me-2 text-primary"></i>Mi perfil
                                    </a>
                                </li>
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">
                                            <i class="bi bi-box-arrow-right me-2 text-danger"></i>Cerrar sesión
                                        </button>
                                    </form>
                                </li>
                            @endauth
                            @guest
                                <li>
                                    <h6 class="dropdown-header">Sesión no iniciada</h6>
                                </li>
                                <li><a class="dropdown-item" href="{{ route('login') }}"><i
                                            class="bi bi-box-arrow-in-right me-2 text-primary"></i>Iniciar Sesión</a>
                                </li>
                                <li><a class="dropdown-item" href="{{ route('register') }}"><i
                                            class="bi bi-person-plus me-2 text-success"></i>Registrarse</a></li>
                            @endguest
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Contenido Principal -->
    <main class="py-4 flex-grow-1">
        <div class="container-fluid px-4">
            <!-- Alertas del Sistema -->
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 d-flex align-items-center"
                    role="alert">
                    <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                    <div>{{ session('success') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 d-flex align-items-center"
                    role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                    <div>{{ session('error') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-warning alert-dismissible fade show shadow-sm border-0" role="alert">
                    <div class="d-flex align-items-center mb-1">
                        <i class="bi bi-exclamation-circle-fill fs-5 me-2"></i>
                        <strong>Por favor verifica los siguientes errores:</strong>
                    </div>
                    <ul class="mb-0 ps-4">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-top py-3 mt-auto">
        <div
            class="container-fluid px-4 d-flex flex-column flex-sm-row justify-content-between align-items-center text-muted small">
            <div>
                &copy; {{ date('Y') }} <strong>SysProducts</strong>
            </div>
            <div class="mt-2 mt-sm-0">
                <span
                    class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                    <i class="bi bi-shield-check me-1"></i>
                    Laravel 13</span>
                <span class="badge bg-success-subtle text-success border me-2"><i class="bi bi-database-check me-1"></i>
                    MySQL</span>
                <span class="badge bg-light text-dark border me-2">Bootstrap 5</span>
                <span class="badge bg-light text-dark border">DataTables</span>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>

</html>