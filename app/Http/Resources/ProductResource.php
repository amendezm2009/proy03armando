<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\PermissionName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            'stock_status' => $this->stockStatus(),
            // El precio de compra es interno: solo lo ve quien puede editar productos (como en la ficha web).
            'purchase_price' => $this->when(
                $request->user()?->can(PermissionName::UpdateProducts->value),
                fn (): float => (float) $this->purchase_price,
            ),
            'sale_price' => (float) $this->sale_price,
            'is_active' => $this->is_active,
            'classifications' => ClassificationResource::collection($this->whenLoaded('classifications')),
            'cover_url' => $this->whenLoaded('cover', fn (): ?string => $this->cover?->thumbnailUrl()),
            'media' => ProductMediaResource::collection($this->whenLoaded('media')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
