@extends('layouts.app')

@section('title', 'Panel de administración')

@section('content')
    @php
        $products = $stats['products'];
        // Con menos elementos que la opción más pequeña del selector "Mostrar" no hay nada que elegir.
        $minSize = min(config('catalog.page_sizes'));
        $money = fn (float $value): string => Illuminate\Support\Number::currency($value, config('catalog.currency'), config('catalog.currency_locale'));
        $cards = [
            ['icon' => 'boxes', 'tone' => 'primary', 'label' => 'Productos', 'value' => $products['total'],
                'detail' => "{$products['active']} activos · {$products['inactive']} inactivos", 'href' => route('products.index')],
            ['icon' => 'x-octagon', 'tone' => 'danger', 'label' => 'Sin existencias', 'value' => $products['out_of_stock'],
                'detail' => 'Productos agotados', 'href' => route('products.index', ['stock_status' => App\Models\Product::STOCK_OUT])],
            ['icon' => 'exclamation-triangle', 'tone' => 'warning', 'label' => 'Stock bajo', 'value' => $products['low_stock'],
                'detail' => 'En el mínimo o por debajo', 'href' => route('products.index', ['stock_status' => App\Models\Product::STOCK_LOW])],
            ['icon' => 'tags', 'tone' => 'info', 'label' => 'Clasificaciones', 'value' => $stats['classifications']['total'],
                'detail' => "{$stats['classifications']['active']} activas", 'href' => route('classifications.index')],
            ['icon' => 'people', 'tone' => 'success', 'label' => 'Usuarios activos', 'value' => $stats['users']['active'],
                'detail' => "{$stats['users']['inactive']} desactivados · {$stats['users']['pending_invitations']} invitaciones pendientes", 'href' => route('users.index')],
            ['icon' => 'cash-stack', 'tone' => 'secondary', 'label' => 'Valor del inventario', 'value' => $money($stats['inventory_value']),
                'detail' => 'Existencias × precio de compra', 'href' => null],
        ];
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-speedometer2 me-2"></i> Panel de administración</h1>
        <div class="d-flex flex-wrap gap-2">
            @can(App\PermissionName::CreateProducts->value)
                <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nuevo producto</a>
            @endcan
            @can(App\PermissionName::CreateClassifications->value)
                <a href="{{ route('classifications.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-tag me-1"></i> Nueva clasificación</a>
            @endcan
            @can(App\PermissionName::CreateUsers->value)
                <a href="{{ route('users.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i> Invitar usuario</a>
            @endcan
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($cards as $card)
            <div class="col-6 col-lg-4 col-xl-2">
                <div class="card h-100 mb-0">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 text-{{ $card['tone'] }} small fw-semibold mb-1">
                            <i class="bi bi-{{ $card['icon'] }}"></i> {{ $card['label'] }}
                        </div>
                        <div class="fs-3 fw-bold lh-1 mb-1">
                            @if ($card['href'])
                                <a href="{{ $card['href'] }}" class="text-reset text-decoration-none stretched-link">{{ $card['value'] }}</a>
                            @else
                                {{ $card['value'] }}
                            @endif
                        </div>
                        <div class="text-muted small">{{ $card['detail'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card" id="atencion">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle me-1"></i> Requieren atención</span>
                    <small class="text-muted fw-normal">Sin stock o en el mínimo</small>
                </div>
                <div class="card-body">
                    @if ($stats['attention']->isEmpty())
                        <p class="text-muted mb-0"><i class="bi bi-check-circle text-success me-1"></i> Todos los productos tienen existencias suficientes.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>Producto</th><th class="text-center">Existencias</th><th class="text-center">Mínimo</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($stats['attention'] as $product)
                                        <tr>
                                            <td>
                                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">{{ $product->name }}</a>
                                                <div class="small text-muted">SKU {{ $product->sku }}</div>
                                            </td>
                                            <td class="text-center">{{ $product->stock }}</td>
                                            <td class="text-center text-muted">{{ $product->min_stock }}</td>
                                            <td class="text-end">
                                                @if ($product->stockStatus() === App\Models\Product::STOCK_OUT)
                                                    <span class="badge bg-danger-subtle text-danger border">Sin stock</span>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border">Stock bajo</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($stats['attention']->total() > $minSize)
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <x-per-page-select name="attention_per_page" :paginator="$stats['attention']" anchor="atencion" />
                            @if ($stats['attention']->hasPages())
                                {{ $stats['attention']->links('pagination::bootstrap-5') }}
                            @endif
                        </div>
                    @endif

                    <hr>
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                        <span><i class="bi bi-image me-1"></i> {{ $products['without_cover'] }} sin foto de portada</span>
                        <span><i class="bi bi-upc me-1"></i> {{ $products['without_barcode'] }} sin código de barras</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card" id="ultimos-productos">
                <div class="card-header"><i class="bi bi-clock-history me-1"></i> Últimos productos</div>
                <ul class="list-group list-group-flush">
                    @forelse ($stats['latest_products'] as $product)
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">{{ $product->name }}</a>
                                <div class="small text-muted">
                                    {{ $product->updated_at->format('d/m/Y H:i') }}
                                    @if ($product->updater)
                                        · {{ $product->updater->name }}
                                    @endif
                                </div>
                            </div>
                            @unless ($product->is_active)
                                <span class="badge bg-secondary-subtle text-secondary border">Inactivo</span>
                            @endunless
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Aún no hay productos.</li>
                    @endforelse
                </ul>
                @if ($stats['latest_products']->total() > $minSize)
                    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <x-per-page-select name="products_per_page" :paginator="$stats['latest_products']" anchor="ultimos-productos" />
                        @if ($stats['latest_products']->hasPages())
                            {{ $stats['latest_products']->links('pagination::bootstrap-5') }}
                        @endif
                    </div>
                @endif
            </div>

            <div class="card" id="usuarios-recientes">
                <div class="card-header"><i class="bi bi-person-lines-fill me-1"></i> Usuarios recientes</div>
                <ul class="list-group list-group-flush">
                    @foreach ($stats['latest_users'] as $user)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <x-avatar :user="$user" :size="32" />
                                <div>
                                    <div class="fw-semibold">{{ $user->name }}</div>
                                    <div class="small text-muted">{{ $user->email }}</div>
                                </div>
                            </div>
                            <div class="text-end">
                                @foreach ($user->roles as $role)
                                    <span class="badge bg-primary-subtle text-primary border">{{ App\RoleName::tryFrom($role->name)?->label() ?? $role->name }}</span>
                                @endforeach
                                @unless ($user->is_active)
                                    <span class="badge bg-secondary-subtle text-secondary border">Desactivado</span>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($stats['latest_users']->total() > $minSize)
                    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <x-per-page-select name="users_per_page" :paginator="$stats['latest_users']" anchor="usuarios-recientes" />
                        @if ($stats['latest_users']->hasPages())
                            {{ $stats['latest_users']->links('pagination::bootstrap-5') }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
