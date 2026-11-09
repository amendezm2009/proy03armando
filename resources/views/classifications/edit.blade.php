@extends('layouts.app')

@section('title', 'Editar clasificación')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Editar clasificación</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('classifications.update', $classification) }}" class="needs-validation" novalidate>
                        @method('PUT')
                        @include('classifications._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
