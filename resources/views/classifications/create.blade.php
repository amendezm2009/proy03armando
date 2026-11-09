@extends('layouts.app')

@section('title', 'Nueva clasificación')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Nueva clasificación</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('classifications.store') }}" class="needs-validation" novalidate>
                        @include('classifications._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
