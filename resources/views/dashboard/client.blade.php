@extends('layouts.app')

@section('title', 'Mi panel')

@section('content')
    <div class="card">
        <div class="card-body p-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-6">
                    <h1 class="h4 mb-1">Hola, {{ auth()->user()->name }}</h1>
                    <p class="text-muted mb-0">Explora el catálogo de productos disponibles.</p>
                </div>
                <div class="col-lg-6">
                    <form method="GET" action="{{ route('products.index') }}" class="input-group">
                        <input type="search" name="search" maxlength="100" class="form-control" placeholder="Buscar un producto por nombre o código"
                            aria-label="Buscar un producto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Buscar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-2" id="novedades">
                <h2 class="h5 mb-0"><i class="bi bi-stars me-1"></i> Novedades</h2>
                @can(App\PermissionName::ViewProducts->value)
                    <a href="{{ route('products.index') }}" class="small text-decoration-none">Ver todos los productos <i class="bi bi-arrow-right"></i></a>
                @endcan
            </div>

            @if ($newest->isEmpty())
                <div class="card"><div class="card-body text-muted">Todavía no hay productos disponibles.</div></div>
            @else
                <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3">
                    @foreach ($newest as $product)
                        @include('catalog._card', ['product' => $product, 'href' => route('products.show', $product), 'col' => 'col', 'compact' => true])
                    @endforeach
                </div>

                @if ($newest->total() > min(config('catalog.page_sizes')))
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <x-per-page-select name="per_page" :paginator="$newest" anchor="novedades" />
                        @if ($newest->hasPages())
                            {{ $newest->links('pagination::bootstrap-5') }}
                        @endif
                    </div>
                @endif
            @endif
        </div>

        <div class="col-lg-3">
            <div class="card">
                <div class="card-header"><i class="bi bi-tags me-1"></i> Clasificaciones</div>
                <div class="card-body">
                    @forelse ($classifications as $classification)
                        <a href="{{ route('products.index', ['classifications' => [$classification['id']]]) }}"
                            class="badge badge-tag text-white text-decoration-none d-inline-block mb-1"
                            style="background-color: {{ $classification['color_badge'] }}">
                            {{ $classification['name'] }} <span class="opacity-75">· {{ $classification['total'] }}</span>
                        </a>
                    @empty
                        <span class="text-muted small">Aún no hay clasificaciones con productos.</span>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-person-circle me-1"></i> Mi cuenta</div>
                <div class="list-group list-group-flush">
                    <a href="{{ route('profile.edit') }}" class="list-group-item list-group-item-action">
                        <i class="bi bi-person-gear me-2 text-primary"></i> Editar mi perfil
                    </a>
                    <a href="{{ route('catalog.index') }}" class="list-group-item list-group-item-action">
                        <i class="bi bi-shop me-2 text-primary"></i> Ver el catálogo público
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
