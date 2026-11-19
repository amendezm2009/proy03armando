<?php

namespace App\Http\Controllers;

use App\Actions\SaveProduct;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductRequest;
use App\Models\Classification;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): View
    {
        $filters = $request->validated();

        return view('products.index', [
            'products' => Product::filter($filters)
                ->with(['classifications', 'cover'])
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? 10)
                ->withQueryString(),
            'filters' => $filters,
            'selectedClassifications' => $this->selectedClassifications($filters['classifications'] ?? []),
        ]);
    }

    public function create(): View
    {
        return view('products.create', [
            'selectedClassifications' => $this->selectedClassifications(old('classification_ids', [])),
        ]);
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->handle($request->validated(), $request->user());

        return to_route('products.show', $product)->with('success', 'Producto creado.');
    }

    public function show(Product $product): View
    {
        return view('products.show', ['product' => $product->load(['classifications', 'creator', 'updater', 'media'])]);
    }

    public function edit(Product $product): View
    {
        return view('products.edit', [
            'product' => $product,
            'selectedClassifications' => $this->selectedClassifications(
                old('classification_ids', $product->classifications()->pluck('classifications.id')->all())
            ),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $save->handle($request->validated(), $request->user(), $product);

        return to_route('products.show', $product)->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return to_route('products.index')->with('success', 'Producto eliminado.');
    }

    /**
     * Las clasificaciones ya elegidas, para pintarlas en el selector (que no carga el catálogo completo).
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Classification>
     */
    private function selectedClassifications(array $ids): Collection
    {
        $ids = array_filter(array_map('intval', $ids));

        return Classification::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'color_badge']);
    }
}
