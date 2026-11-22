<?php

namespace App\Actions;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Datos del panel del cliente (/cliente/dashboard): los productos activos más recientes y las clasificaciones
 * con más productos activos. El conteo por clasificación recorre la tabla pivote, así que se guarda 10 minutos
 * en la caché (CACHE_KEY): para "las más usadas" no hace falta un dato al segundo.
 *
 * USO: view('dashboard.client', app(BuildClientDashboard::class)->handle());
 */
class BuildClientDashboard
{
    public const string CACHE_KEY = 'dashboard.client.top-classifications';

    private const int CLASSIFICATIONS_SIZE = 8;

    /**
     * @return array{newest: LengthAwarePaginator<int, Product>, classifications: list<array{id: int, name: string, color_badge: string, total: int}>}
     */
    public function handle(?int $newestSize = null): array
    {
        return [
            'newest' => Product::where('is_active', true)
                ->with('cover')
                ->latest()
                ->orderByDesc('id')
                ->paginate($newestSize ?? (int) config('catalog.dashboard_page_size'))
                ->withQueryString()
                ->fragment('novedades'),
            'classifications' => Cache::remember(self::CACHE_KEY, 600, fn (): array => $this->topClassifications()),
        ];
    }

    /**
     * @return list<array{id: int, name: string, color_badge: string, total: int}>
     */
    private function topClassifications(): array
    {
        return DB::table('classification_product')
            ->join('classifications', 'classifications.id', '=', 'classification_product.classification_id')
            ->join('products', 'products.id', '=', 'classification_product.product_id')
            ->where('classifications.is_active', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('classifications.id', 'classifications.name', 'classifications.color_badge')
            ->orderByDesc('total')
            ->orderBy('classifications.name')
            ->limit(self::CLASSIFICATIONS_SIZE)
            ->get(['classifications.id', 'classifications.name', 'classifications.color_badge', DB::raw('count(*) as total')])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
