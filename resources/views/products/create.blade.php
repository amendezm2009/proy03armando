@extends('layouts.app')

@section('title', 'Nuevo producto')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card">
                <div class="card-header">Nuevo producto</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('products.store') }}" class="needs-validation" novalidate>
                        @include('products._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
