@extends('layouts.app')

@section('title', 'Restablecer contraseña')

@section('content')
    <x-auth.card title="Restablecer contraseña" icon="bi-key" width="col-md-7 col-lg-5">
        <form method="POST" action="{{ route('password.store') }}" class="needs-validation" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

            <div class="mb-3">
                <x-form.input name="email" type="email" label="Correo electrónico" :value="$email" required
                    autocomplete="username" hint="Ingresa un correo válido." />
            </div>

            <div class="mb-3">
                <x-form.password name="password" label="Contraseña nueva" required autofocus minlength="8"
                    hint="Mínimo 8 caracteres." />
            </div>

            <div class="mb-3">
                <x-form.password name="password_confirmation" label="Confirmar contraseña" required minlength="8"
                    hint="Repite la contraseña." />
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-lg me-1"></i> Guardar contraseña
            </button>
        </form>
    </x-auth.card>
@endsection
