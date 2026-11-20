@extends('layouts.app')

@section('title', 'Productos')

@section('content')
    @php
        $estado = (string) ($filters['is_active'] ?? '');
        $existencias = (string) ($filters['stock_status'] ?? '');
        $porPagina = (int) ($filters['per_page'] ?? 10);
    @endphp

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-boxes me-1"></i> Productos</span>
            @can(App\PermissionName::CreateProducts->value)
                <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Nuevo
                </a>
            @endcan
        </div>

        <div class="card-body">
            <form method="GET" action="{{ route('products.index') }}" class="row g-2 mb-3">
                <div class="col-md-4">
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100"
                        class="form-control" placeholder="Nombre, SKU o código de barras" aria-label="Buscar">
                </div>
                <div class="col-md-4">
                    <x-classification-picker id="classifications" name="classifications[]" :selected="$selectedClassifications"
                        label="Clasificaciones (basta con tener alguna)" />
                </div>
                <div class="col-md-4">
                    <div class="row g-2">
                        <div class="col-6">
                            <select name="is_active" class="form-select" aria-label="Estado">
                                <option value="">Todos los estados</option>
                                <option value="1" @selected($estado === '1')>Activos</option>
                                <option value="0" @selected($estado === '0')>Inactivos</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <select name="stock_status" class="form-select" aria-label="Existencias">
                                <option value="">Todas las existencias</option>
                                <option value="{{ App\Models\Product::STOCK_OUT }}" @selected($existencias === App\Models\Product::STOCK_OUT)>Sin stock</option>
                                <option value="{{ App\Models\Product::STOCK_LOW }}" @selected($existencias === App\Models\Product::STOCK_LOW)>Stock bajo</option>
                                <option value="{{ App\Models\Product::STOCK_OK }}" @selected($existencias === App\Models\Product::STOCK_OK)>Suficiente</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <input type="number" name="price_min" value="{{ $filters['price_min'] ?? '' }}" min="0"
                                step="0.01" class="form-control" placeholder="Precio mín." aria-label="Precio mínimo">
                        </div>
                        <div class="col-6">
                            <input type="number" name="price_max" value="{{ $filters['price_max'] ?? '' }}" min="0"
                                step="0.01" class="form-control" placeholder="Precio máx." aria-label="Precio máximo">
                        </div>
                    </div>
                </div>
                <div class="col-12 d-flex flex-wrap justify-content-between gap-2">
                    <select name="per_page" class="form-select w-auto" aria-label="Registros por página">
                        @foreach ([5, 10, 25, 50] as $opcion)
                            <option value="{{ $opcion }}" @selected($porPagina === $opcion)>{{ $opcion }} por página
                            </option>
                        @endforeach
                    </select>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary" title="Limpiar filtros">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </div>
                @if ($errors->any())
                    <div class="col-12">
                        <div class="text-danger small">{{ $errors->first() }}</div>
                    </div>
                @endif
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 64px"></th>
                            <th>Producto</th>
                            <th>Clasificaciones</th>
                            <th class="text-end">Precio</th>
                            <th class="text-center">Existencias</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr>
                                <td>
                                    @if ($product->cover?->thumbnailUrl())
                                        <img src="{{ $product->cover->thumbnailUrl() }}" alt="" width="48" height="48"
                                            class="rounded object-fit-cover" loading="lazy">
                                    @else
                                        <div class="bg-body-secondary text-secondary rounded d-flex align-items-center justify-content-center"
                                            style="width: 48px; height: 48px" aria-hidden="true">
                                            <i class="bi bi-image fs-5"></i>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('products.show', $product) }}"
                                        class="fw-semibold text-decoration-none">{{ $product->name }}</a>
                                    <div class="small text-muted">
                                        SKU {{ $product->sku }}
                                        @if ($product->barcode)
                                            · <i class="bi bi-upc"></i> {{ $product->barcode }}
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @foreach ($product->classifications as $classification)
                                        <span class="badge badge-tag text-white"
                                            style="background-color: {{ $classification->color_badge }}">{{ $classification->name }}</span>
                                    @endforeach
                                </td>
                                <td class="text-end text-nowrap">{{ $product->formattedSalePrice() }}</td>
                                <td class="text-center text-nowrap">
                                    {{ $product->stock }}
                                    @if ($product->stockStatus() === App\Models\Product::STOCK_OUT)
                                        <span class="badge bg-danger-subtle text-danger border">Sin stock</span>
                                    @elseif ($product->stockStatus() === App\Models\Product::STOCK_LOW)
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">Stock bajo</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($product->is_active)
                                        <span class="badge bg-success-subtle text-success border">Activo</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactivo</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary btn-sm"
                                        title="Ver">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @can(App\PermissionName::UpdateProducts->value)
                                        <a href="{{ route('products.edit', $product) }}"
                                            class="btn btn-outline-primary btn-sm" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endcan
                                    @can(App\PermissionName::DeleteProducts->value)
                                        <form method="POST" action="{{ route('products.destroy', $product) }}"
                                            class="d-inline js-delete-form">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No se encontraron productos.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links('pagination::bootstrap-5') }}

            @unless ($products->hasPages())
                <small class="text-muted">Total: {{ $products->total() }}</small>
            @endunless
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.js-delete-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!confirm('¿Eliminar este producto?')) {
                        event.preventDefault();
                    }
                });
            });
        });
    </script>
@endpush
