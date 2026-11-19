<?php

namespace App\Http\Resources;

use App\Models\ProductMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Requiere la relación "product" cargada (Product::media() la rellena sola) para saber si es la portada.
 *
 * @mixin ProductMedia
 */
class ProductMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->url(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'position' => $this->position,
            'is_cover' => $this->product->cover_media_id === $this->id,
        ];
    }
}
