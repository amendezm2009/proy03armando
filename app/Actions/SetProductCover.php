<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Validation\ValidationException;

/**
 * Elige la portada de un producto. Debe ser una foto: la miniatura del listado y de la API sale de ella.
 *
 * USO: app(SetProductCover::class)->handle($media);
 */
class SetProductCover
{
    /**
     * @throws ValidationException
     */
    public function handle(ProductMedia $media): ProductMedia
    {
        if (! $media->isImage()) {
            throw ValidationException::withMessages(['media' => __('validation.media_cover_image')]);
        }

        Product::query()->whereKey($media->product_id)->update(['cover_media_id' => $media->id]);

        return $media;
    }
}
