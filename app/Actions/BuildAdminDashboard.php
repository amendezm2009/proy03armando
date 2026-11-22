<?php

namespace App\Actions;

use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Datos del panel del administrador (/admin/dashboard): totales de productos, existencias, clasificaciones y
 * usuarios, el valor del inventario (existencias por precio de compra, un dato interno) y listas de lo que
 * requiere atención o cambió hace poco. Son consultas de conteo y listas cortas con límite, sin recorrer tablas.
 *
 * USO: view('dashboard.admin', ['stats' => app(BuildAdminDashboard::class)->handle()]);
 */
class BuildAdminDashboard
{
    /**
     * @return array{
     *     products: array<string, int>,
     *     inventory_value: float,
     *     classifications: array<string, int>,
     *     users: array<string, int>,
     *     attention: LengthAwarePaginator<int, Product>,
     *     latest_products: LengthAwarePaginator<int, Product>,
     *     latest_users: LengthAwarePaginator<int, User>,
     * }
     */
    public function handle(?int $attentionSize = null, ?int $productsSize = null, ?int $usersSize = null): array
    {
        // Cada lista pagina con su propio parámetro de página y su propio tamaño (por defecto, el de la configuración).
        $default = (int) config('catalog.dashboard_page_size');

        return [
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('is_active', true)->count(),
                'inactive' => Product::where('is_active', false)->count(),
                'out_of_stock' => Product::where('stock', 0)->count(),
                'low_stock' => Product::where('stock', '>', 0)->whereColumn('stock', '<=', 'min_stock')->count(),
                'without_cover' => Product::whereNull('cover_media_id')->count(),
                'without_barcode' => Product::whereNull('barcode')->count(),
            ],
            'inventory_value' => (float) Product::query()->toBase()
                ->selectRaw('coalesce(sum(stock * purchase_price), 0) as value')
                ->value('value'),
            'classifications' => [
                'total' => Classification::count(),
                'active' => Classification::where('is_active', true)->count(),
            ],
            'users' => [
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'pending_invitations' => User::whereNotNull('invited_at')->whereNull('invitation_accepted_at')->count(),
            ],
            'attention' => Product::query()
                ->where(fn ($query) => $query->where('stock', 0)->orWhereColumn('stock', '<=', 'min_stock'))
                ->with('cover')
                ->orderBy('stock')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($attentionSize ?? $default, pageName: 'attention_page')
                ->withQueryString()
                ->fragment('atencion'),
            'latest_products' => Product::with(['cover', 'updater'])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate($productsSize ?? $default, pageName: 'products_page')
                ->withQueryString()
                ->fragment('ultimos-productos'),
            'latest_users' => User::with('roles')
                ->latest()
                ->orderByDesc('id')
                ->paginate($usersSize ?? $default, pageName: 'users_page')
                ->withQueryString()
                ->fragment('usuarios-recientes'),
        ];
    }
}
