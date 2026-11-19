<?php

namespace App\Http\Controllers\Api;

use App\Actions\AddProductMedia;
use App\Actions\RemoveProductMedia;
use App\Actions\ReorderProductMedia;
use App\Actions\SetProductCover;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductMediaOrderRequest;
use App\Http\Requests\ProductMediaRequest;
use App\Http\Resources\ProductMediaResource;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Multimedia de un producto por la API. Consultar exige products.view; subir, ordenar, elegir portada y
 * eliminar exigen products.update (routes/api.php). Subir es multipart/form-data con "media[]".
 */
class ProductMediaController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductMediaResource::collection($product->media);
    }

    public function store(ProductMediaRequest $request, Product $product, AddProductMedia $add): JsonResponse
    {
        $created = $add->handle($product, Arr::wrap($request->file('media')));

        return ProductMediaResource::collection($created->each->setRelation('product', $product))
            ->response()
            ->setStatusCode(201);
    }

    public function order(ProductMediaOrderRequest $request, Product $product, ReorderProductMedia $reorder): AnonymousResourceCollection
    {
        $reorder->handle($product, $request->validated('ids'));

        return ProductMediaResource::collection($product->media()->get());
    }

    public function cover(Product $product, ProductMedia $media, SetProductCover $setCover): ProductMediaResource
    {
        $this->ensureBelongsTo($product, $media);

        $setCover->handle($media);

        return new ProductMediaResource($media->setRelation('product', $product->refresh()));
    }

    public function destroy(Product $product, ProductMedia $media, RemoveProductMedia $remove): Response
    {
        $this->ensureBelongsTo($product, $media);

        $remove->handle($media);

        return response()->noContent();
    }

    private function ensureBelongsTo(Product $product, ProductMedia $media): void
    {
        abort_unless($media->product_id === $product->id, 404);
    }
}
