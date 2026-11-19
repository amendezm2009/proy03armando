@extends('layouts.app')

@section('title', 'Editar producto')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card">
                <div class="card-header">Editar producto</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('products.update', $product) }}" class="needs-validation" novalidate>
                        @method('PUT')
                        @include('products._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
