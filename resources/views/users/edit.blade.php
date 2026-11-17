@extends('layouts.app')

@section('title', 'Editar usuario')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Editar usuario</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('users.update', $user) }}" class="needs-validation" novalidate>
                        @method('PUT')
                        @include('users._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
