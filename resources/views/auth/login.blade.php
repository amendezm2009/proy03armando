@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <x-auth.card title="Iniciar sesión" icon="bi-box-arrow-in-right">
        <form method="POST" action="{{ route('login.store') }}" class="needs-validation" novalidate>
            @csrf

            <div class="mb-3">
                <x-form.input name="email" type="email" label="Correo electrónico" required autofocus
                    autocomplete="username" hint="Ingresa un correo válido." />
            </div>

            <div class="mb-3">
                <x-form.input name="password" type="password" label="Contraseña" required
                    autocomplete="current-password" hint="Ingresa tu contraseña." />
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

        <x-slot:footer>
            <div class="mb-1"><a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a></div>
            ¿No tienes cuenta? <a href="{{ route('register') }}">Regístrate</a>
        </x-slot:footer>
    </x-auth.card>
@endsection
