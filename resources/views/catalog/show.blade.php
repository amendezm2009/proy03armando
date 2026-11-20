@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <a href="{{ route('catalog.index') }}" class="text-decoration-none small d-inline-block mb-2">
                <i class="bi bi-arrow-left"></i> Volver al catálogo
            </a>

            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <h1 class="h3 mb-0">{{ $product->name }}</h1>
                        @if ($product->stockStatus() === App\Models\Product::STOCK_OUT)
                            <span class="badge bg-danger-subtle text-danger border fs-6">Agotado</span>
                        @else
                            <span class="badge bg-success-subtle text-success border fs-6">Disponible</span>
                        @endif
                    </div>

                    <div class="fs-3 fw-bold text-primary mb-3">{{ $product->formattedSalePrice() }}</div>

                    <div class="mb-3">
                        @foreach ($product->classifications->where('is_active', true) as $classification)
                            <a href="{{ route('catalog.index', ['classification' => $classification->id]) }}"
                                class="badge badge-tag text-white text-decoration-none"
                                style="background-color: {{ $classification->color_badge }}">{{ $classification->name }}</a>
                        @endforeach
                    </div>

                    @if ($product->description)
                        <p class="mb-3" style="white-space: pre-line">{{ $product->description }}</p>
                    @endif

                    <div class="small text-muted">Código: {{ $product->sku }}</div>
                </div>
            </div>

            @if ($product->media->isNotEmpty())
                @include('products._media', ['canManage' => false])
            @endif
        </div>
    </div>
@endsection
