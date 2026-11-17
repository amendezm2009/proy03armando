@extends('layouts.app')

@section('title', 'Editar usuario')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Editar usuario</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('users.update', $user) }}" class="needs-validation" novalidate
                        data-password-confirm="changed" data-password-confirm-fields="role,email"
                        data-confirm-title="Confirmar cambios"
                        data-confirm-message="Cambiar el rol o el correo de una cuenta es una acción sensible. Confirma tu contraseña para guardar.">
                        @method('PUT')
                        @include('users._form')
                    </form>
                </div>
            </div>
        </div>
    </div>

    <x-password-confirm-modal />
@endsection
