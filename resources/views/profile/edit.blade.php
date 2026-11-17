@extends('layouts.app')

@section('title', 'Mi perfil')

@section('content')
    <x-auth.card title="Datos del perfil" icon="bi-person" width="col-md-8 col-lg-6">
        <form method="POST" action="{{ route('profile.update') }}" class="needs-validation" novalidate>
            @csrf
            @method('PATCH')

            <p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

            <div class="mb-3">
                <x-form.input name="name" label="Nombre" :value="$user->name" required maxlength="255"
                    autocomplete="name" hint="Ingresa tu nombre." />
            </div>

            <div class="mb-3">
                <x-form.input name="email" type="email" label="Correo electrónico" :value="$user->email" required
                    maxlength="255" autocomplete="username" hint="Ingresa un correo válido." />
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> Guardar
            </button>
        </form>
    </x-auth.card>

    <div class="mt-4">
        <x-auth.card title="Cambiar contraseña" icon="bi-key" width="col-md-8 col-lg-6">
            <form method="POST" action="{{ route('profile.password.update') }}" class="needs-validation" novalidate>
                @csrf
                @method('PUT')

                {{-- Indica al gestor de contraseñas del navegador qué cuenta cambia de contraseña; sin este
                     campo el navegador no puede saberlo y muestra una lista con todas las cuentas guardadas. --}}
                <input type="text" name="username" value="{{ $user->email }}" autocomplete="username" readonly
                    tabindex="-1" aria-hidden="true" class="visually-hidden">

                <div class="mb-3">
                    <x-form.password name="current_password" label="Contraseña actual" required
                        hint="Ingresa tu contraseña actual." />
                </div>

                <div class="mb-3">
                    <x-form.password name="password" label="Contraseña nueva" required minlength="8"
                        hint="Mínimo 8 caracteres." />
                </div>

                <div class="mb-3">
                    <x-form.password name="password_confirmation" label="Confirmar contraseña" required
                        minlength="8" hint="Repite la contraseña." />
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Cambiar contraseña
                </button>
            </form>
        </x-auth.card>
    </div>
@endsection
