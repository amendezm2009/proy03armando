@extends('layouts.app')

@section('title', 'Nuevo usuario')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Nuevo usuario</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('users.store') }}" class="needs-validation" novalidate>
                        @include('users._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
