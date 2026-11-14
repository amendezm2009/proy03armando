@extends('layouts.app')

@section('title', 'Registro')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-person-plus me-1"></i> Crear cuenta</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('register.store') }}" class="needs-validation" novalidate>
                        @csrf

                        <p class="text-muted small mb-3"><span class="text-danger">*</span> Campo obligatorio</p>

                        <div class="mb-3">
                            <x-form.label for="name" required>Nombre</x-form.label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="255"
                                required autofocus autocomplete="name"
                                class="form-control @error('name') is-invalid @enderror">
                            <div class="invalid-feedback">@error('name'){{ $message }}@else Ingresa tu nombre.@enderror</div>
                        </div>

                        <div class="mb-3">
                            <x-form.label for="email" required>Correo electrónico</x-form.label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="255"
                                required autocomplete="username"
                                class="form-control @error('email') is-invalid @enderror">
                            <div class="invalid-feedback">@error('email'){{ $message }}@else Ingresa un correo válido.@enderror</div>
                        </div>

                        <div class="mb-3">
                            <x-form.label for="password" required>Contraseña</x-form.label>
                            <input type="password" id="password" name="password" minlength="8" required
                                autocomplete="new-password"
                                class="form-control @error('password') is-invalid @enderror">
                            <div class="invalid-feedback">@error('password'){{ $message }}@else Mínimo 8 caracteres.@enderror</div>
                        </div>

                        <div class="mb-3">
                            <x-form.label for="password_confirmation" required>Confirmar contraseña</x-form.label>
                            <input type="password" id="password_confirmation" name="password_confirmation"
                                minlength="8" required autocomplete="new-password" class="form-control">
                            <div class="invalid-feedback">Repite la contraseña.</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-person-plus me-1"></i> Registrarme
                        </button>
                    </form>
                </div>
                <div class="card-footer text-center small">
                    ¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a>
                </div>
            </div>
        </div>
    </div>
@endsection
