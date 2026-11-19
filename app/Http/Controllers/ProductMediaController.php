<?php

namespace App\Http\Controllers;

use App\Actions\AddProductMedia;
use App\Actions\RemoveProductMedia;
use App\Actions\ReorderProductMedia;
use App\Actions\SetProductCover;
use App\Http\Requests\ProductMediaMoveRequest;
use App\Http\Requests\ProductMediaRequest;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Gestión de la multimedia de un producto desde su ficha (products.show). Todas las rutas exigen el
 * permiso products.update y el límite de peticiones "media" (routes/web.php). Los errores de validación
 * vuelven a la ficha y se muestran junto a la galería.
 */
class ProductMediaController extends Controller
{
    public function store(ProductMediaRequest $request, Product $product, AddProductMedia $add): RedirectResponse
    {
        $created = $add->handle($product, Arr::wrap($request->file('media')));

        return to_route('products.show', $product)->with(
            'success',
            $created->count() === 1 ? 'Archivo agregado.' : "{$created->count()} archivos agregados.",
        );
    }

    public function destroy(Product $product, ProductMedia $media, RemoveProductMedia $remove): RedirectResponse
    {
        $this->ensureBelongsTo($product, $media);

        $remove->handle($media);

        return to_route('products.show', $product)->with('success', 'Archivo eliminado.');
    }

    public function cover(Product $product, ProductMedia $media, SetProductCover $setCover): RedirectResponse
    {
        $this->ensureBelongsTo($product, $media);

        $setCover->handle($media);

        return to_route('products.show', $product)->with('success', 'Portada actualizada.');
    }

    public function move(ProductMediaMoveRequest $request, Product $product, ProductMedia $media, ReorderProductMedia $reorder): RedirectResponse
    {
        $this->ensureBelongsTo($product, $media);

        $ids = $product->media()->pluck('id')->all();
        $index = array_search($media->id, $ids, true);
        $target = $request->validated('direction') === 'up' ? $index - 1 : $index + 1;

        if (isset($ids[$target])) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            $reorder->handle($product, $ids);
        }

        return to_route('products.show', $product);
    }

    private function ensureBelongsTo(Product $product, ProductMedia $media): void
    {
        abort_unless($media->product_id === $product->id, 404);
    }
}
