@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-box-seam me-1"></i> {{ $product->name }}</span>
                    <div class="d-flex gap-2">
                        @can(App\PermissionName::UpdateProducts->value)
                            <a href="{{ route('products.edit', $product) }}" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-pencil me-1"></i> Editar
                            </a>
                        @endcan
                        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary btn-sm">Volver</a>
                    </div>
                </div>

                <div class="card-body">
                    <div class="mb-3">
                        @forelse ($product->classifications as $classification)
                            <span class="badge badge-tag text-white"
                                style="background-color: {{ $classification->color_badge }}">{{ $classification->name }}</span>
                        @empty
                            <span class="text-muted small">Sin clasificaciones.</span>
                        @endforelse
                    </div>

                    @if ($product->description)
                        <p style="white-space: pre-line">{{ $product->description }}</p>
                    @endif

                    <dl class="row mb-0">
                        <dt class="col-sm-4">SKU</dt>
                        <dd class="col-sm-8">{{ $product->sku }}</dd>

                        <dt class="col-sm-4">Código de barras</dt>
                        <dd class="col-sm-8">{{ $product->barcode ?? '—' }}</dd>

                        <dt class="col-sm-4">Precio de venta</dt>
                        <dd class="col-sm-8">{{ $product->formattedSalePrice() }}</dd>

                        @can(App\PermissionName::UpdateProducts->value)
                            <dt class="col-sm-4">Precio de compra</dt>
                            <dd class="col-sm-8">{{ $product->formattedPurchasePrice() }}</dd>
                        @endcan

                        <dt class="col-sm-4">Existencias</dt>
                        <dd class="col-sm-8">
                            {{ $product->stock }} (mínimo {{ $product->min_stock }})
                            @if ($product->stockStatus() === App\Models\Product::STOCK_OUT)
                                <span class="badge bg-danger-subtle text-danger border">Sin stock</span>
                            @elseif ($product->stockStatus() === App\Models\Product::STOCK_LOW)
                                <span class="badge bg-warning-subtle text-warning-emphasis border">Stock bajo</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Estado</dt>
                        <dd class="col-sm-8">
                            @if ($product->is_active)
                                <span class="badge bg-success-subtle text-success border">Activo</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border">Inactivo</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Creado</dt>
                        <dd class="col-sm-8">
                            {{ $product->created_at->format('d/m/Y H:i') }}
                            @if ($product->creator)
                                por {{ $product->creator->name }}
                            @endif
                        </dd>

                        <dt class="col-sm-4">Última modificación</dt>
                        <dd class="col-sm-8">
                            {{ $product->updated_at->format('d/m/Y H:i') }}
                            @if ($product->updater)
                                por {{ $product->updater->name }}
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
