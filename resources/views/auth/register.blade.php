@extends('layouts.app')

@section('title', 'Registro')

@section('content')
    <x-auth.card title="Crear cuenta" icon="bi-person-plus" width="col-md-7 col-lg-5">
        <form method="POST" action="{{ route('register.store') }}" class="needs-validation" novalidate>
            @csrf

            <p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

            <div class="mb-3">
                <x-form.input name="name" label="Nombre" required autofocus maxlength="255" autocomplete="name"
                    hint="Ingresa tu nombre." />
            </div>

            <div class="mb-3">
                <x-form.input name="email" type="email" label="Correo electrónico" required maxlength="255"
                    autocomplete="username" hint="Ingresa un correo válido." />
            </div>

            <div class="mb-3">
                <x-form.password name="password" label="Contraseña" required minlength="8"
                    hint="Mínimo 8 caracteres." />
            </div>

            <div class="mb-3">
                <x-form.password name="password_confirmation" label="Confirmar contraseña" required minlength="8"
                    hint="Repite la contraseña." />
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-person-plus me-1"></i> Registrarme
            </button>
        </form>

        <x-slot:footer>
            ¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a>
        </x-slot:footer>
    </x-auth.card>
@endsection
