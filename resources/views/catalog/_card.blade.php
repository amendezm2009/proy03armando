{{--
    Tarjeta de un producto para cuadrículas (panel del cliente y catálogo público). Solo muestra datos que puede
    ver cualquier cliente: portada, nombre, precio de venta y disponibilidad (nunca el precio de compra ni las
    existencias exactas).

    USO: @include('catalog._card', ['product' => $product, 'href' => route('catalog.show', $product)])
    Opcionales:
      - col: clases de la columna (por defecto 4 por fila en pantallas grandes). Con una fila "row-cols-*" se pasa 'col'.
      - compact: true = imagen más baja (16:10) y nombre en una sola línea, para que quepan más filas en pantalla.
--}}
@php
    $compact ??= false;
@endphp
<div class="{{ $col ?? 'col-6 col-md-4 col-xl-3' }}">
    <div class="card h-100 mb-0">
        <a href="{{ $href }}" class="d-block text-decoration-none" tabindex="-1" aria-hidden="true">
            @if ($product->cover?->thumbnailUrl())
                <img src="{{ $product->cover->thumbnailUrl() }}" alt="" class="card-img-top object-fit-cover"
                    style="aspect-ratio: {{ $compact ? '16 / 10' : '1 / 1' }}" loading="lazy">
            @else
                <div class="bg-body-secondary text-secondary d-flex align-items-center justify-content-center card-img-top"
                    style="aspect-ratio: {{ $compact ? '16 / 10' : '1 / 1' }}">
                    <i class="bi bi-image {{ $compact ? 'fs-2' : 'fs-1' }}"></i>
                </div>
            @endif
        </a>
        <div class="card-body {{ $compact ? 'p-2' : '' }}">
            <a href="{{ $href }}" title="{{ $product->name }}"
                class="fw-semibold text-decoration-none text-dark stretched-link {{ $compact ? 'd-block text-truncate small' : '' }}">{{ $product->name }}</a>
            <div class="d-flex justify-content-between align-items-center {{ $compact ? 'mt-1' : 'mt-2' }}">
                <span class="fw-bold text-primary {{ $compact ? 'small' : '' }}">{{ $product->formattedSalePrice() }}</span>
                @if ($product->stockStatus() === App\Models\Product::STOCK_OUT)
                    <span class="badge bg-danger-subtle text-danger border">Agotado</span>
                @else
                    <span class="badge bg-success-subtle text-success border">Disponible</span>
                @endif
            </div>
        </div>
    </div>
</div>
