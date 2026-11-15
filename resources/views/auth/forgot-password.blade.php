@extends('layouts.app')

@section('title', 'Recuperar contraseña')

@section('content')
    <x-auth.card title="Recuperar contraseña" icon="bi-key">
        <p class="text-muted small">
            Escribe el correo de tu cuenta y te enviaremos un enlace para crear una contraseña nueva.
        </p>

        <form method="POST" action="{{ route('password.email') }}" class="needs-validation" novalidate>
            @csrf

            <div class="mb-3">
                <x-form.input name="email" type="email" label="Correo electrónico" required autofocus
                    autocomplete="username" hint="Ingresa un correo válido." />
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-envelope me-1"></i> Enviar enlace
            </button>
        </form>

        <x-slot:footer>
            <a href="{{ route('login') }}">Volver a iniciar sesión</a>
        </x-slot:footer>
    </x-auth.card>
@endsection
