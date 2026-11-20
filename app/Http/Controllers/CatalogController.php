<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogIndexRequest;
use App\Models\Classification;
use App\Models\Product;
use Illuminate\View\View;

/**
 * Catálogo público para visitantes (/invitado/catalogo), sin sesión. Solo productos ACTIVOS (uno inactivo o
 * eliminado responde 404) y sin datos internos: no se muestran el precio de compra, las existencias exactas
 * ni quién lo modificó. Es la dirección que codifica el QR de cada producto. Límite de peticiones: "catalog".
 */
class CatalogController extends Controller
{
    public function index(CatalogIndexRequest $request): View
    {
        $filters = $request->validated();
        $classification = isset($filters['classification'])
            ? Classification::where('is_active', true)->find($filters['classification'])
            : null;

        return view('catalog.index', [
            'products' => Product::filter([
                'search' => $filters['search'] ?? null,
                'classifications' => $classification ? [$classification->id] : null,
                'is_active' => true,
            ])
                ->with(['cover', 'classifications'])
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? config('catalog.catalog_page_size'))
                ->withQueryString(),
            'search' => $filters['search'] ?? '',
            'classification' => $classification,
        ]);
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        return view('catalog.show', ['product' => $product->load(['classifications', 'media'])]);
    }
}
