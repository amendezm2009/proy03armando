@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-box-arrow-in-right me-1"></i> Iniciar sesión</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('login.store') }}" class="needs-validation" novalidate>
                        @csrf

                        <div class="mb-3">
                            <x-form.label for="email" required>Correo electrónico</x-form.label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                                autocomplete="username" class="form-control @error('email') is-invalid @enderror">
                            <div class="invalid-feedback">@error('email'){{ $message }}@else Ingresa un correo válido.@enderror</div>
                        </div>

                        <div class="mb-3">
                            <x-form.label for="password" required>Contraseña</x-form.label>
                            <input type="password" id="password" name="password" required
                                autocomplete="current-password"
                                class="form-control @error('password') is-invalid @enderror">
                            <div class="invalid-feedback">@error('password'){{ $message }}@else Ingresa tu contraseña.@enderror</div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1"
                                @checked(old('remember'))>
                            <label class="form-check-label" for="remember">Recordarme</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                        </button>
                    </form>
                </div>
                <div class="card-footer text-center small">
                    ¿No tienes cuenta? <a href="{{ route('register') }}">Regístrate</a>
                </div>
            </div>
        </div>
    </div>
@endsection
