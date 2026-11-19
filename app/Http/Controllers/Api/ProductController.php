<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return ProductResource::collection(
            Product::filter($filters)
                ->with('classifications')
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString()
        );
    }

    public function store(ProductRequest $request, SaveProduct $save): JsonResponse
    {
        return (new ProductResource($save->handle($request->validated(), $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('classifications'));
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        return new ProductResource($save->handle($request->validated(), $request->user(), $product));
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
