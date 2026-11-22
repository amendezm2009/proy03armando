@extends('layouts.app')

@section('title', 'Catálogo de productos')

@section('content')
    {{-- Título y buscador en la misma línea para dejar sitio a la cuadrícula: con el tamaño por defecto (10) se
         ven dos filas de cinco productos y el paginador sin desplazarse. --}}
    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i> Catálogo de productos</h1>

                <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 flex-grow-1">
                    <form method="GET" action="{{ route('catalog.index') }}" class="input-group" style="max-width: 460px">
                        @if ($classification)
                            <input type="hidden" name="classification" value="{{ $classification->id }}">
                        @endif
                        <input type="hidden" name="per_page" value="{{ $products->perPage() }}">
                        <input type="search" name="search" value="{{ $search }}" maxlength="100" class="form-control"
                            placeholder="Buscar por nombre o código" aria-label="Buscar en el catálogo">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Buscar</button>
                    </form>

                    <x-per-page-select name="per_page" :paginator="$products" />
                </div>
            </div>

            @if ($classification || $search !== '')
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
                    <span class="text-muted">Filtrando por:</span>
                    @if ($classification)
                        <span class="badge badge-tag text-white" style="background-color: {{ $classification->color_badge }}">{{ $classification->name }}</span>
                    @endif
                    @if ($search !== '')
                        <span class="badge bg-secondary-subtle text-secondary border">“{{ $search }}”</span>
                    @endif
                    <a href="{{ route('catalog.index', ['per_page' => $products->perPage()]) }}" class="text-decoration-none"><i class="bi bi-x-lg"></i> Quitar filtros</a>
                </div>
            @endif
        </div>
    </div>

    @if ($products->isEmpty())
        <div class="card"><div class="card-body text-muted text-center py-5">No se encontraron productos.</div></div>
    @else
        {{-- Cinco por fila desde pantallas grandes (lg); en pantallas menores caben menos. --}}
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3">
            @foreach ($products as $product)
                @include('catalog._card', ['product' => $product, 'href' => route('catalog.show', $product), 'col' => 'col', 'compact' => true])
            @endforeach
        </div>

        <div class="mt-3">{{ $products->links('pagination::bootstrap-5') }}</div>
    @endif
@endsection
