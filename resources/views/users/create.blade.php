@extends('layouts.app')

@section('title', 'Nuevo usuario')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Nuevo usuario</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('users.store') }}" class="needs-validation" novalidate
                        data-password-confirm="always" data-confirm-title="Crear usuario"
                        data-confirm-message="Confirma tu contraseña para crear la cuenta y enviar la invitación por correo.">
                        @include('users._form')
                    </form>
                </div>
            </div>
        </div>
    </div>

    <x-password-confirm-modal />
@endsection
