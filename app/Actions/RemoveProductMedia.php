<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Elimina una foto o un video de un producto: la fila y sus archivos (el archivo y la miniatura).
 *
 * Las posiciones de los que quedan se vuelven a numerar desde 0 y, si era la portada, la nueva portada es
 * la primera foto que quede (o ninguna). Los archivos se borran cuando la transacción ya terminó.
 *
 * USO: app(RemoveProductMedia::class)->handle($media);
 */
class RemoveProductMedia
{
    public function handle(ProductMedia $media): void
    {
        $files = collect([$media->path, $media->thumbnail_path])
            ->filter()
            ->map(fn (string $name): string => AddProductMedia::DIRECTORY.'/'.$name)
            ->all();

        DB::transaction(function () use ($media): void {
            $product = Product::query()->lockForUpdate()->findOrFail($media->product_id);
            $wasCover = $product->cover_media_id === $media->id;

            $media->delete();

            $remaining = $product->media()->get();
            $remaining->each(function (ProductMedia $item, int $position): void {
                if ($item->position !== $position) {
                    $item->forceFill(['position' => $position])->save();
                }
            });

            if ($wasCover) {
                $product->forceFill([
                    'cover_media_id' => $remaining->first(fn (ProductMedia $item): bool => $item->isImage())?->id,
                ])->save();
            }
        });

        Storage::disk(AddProductMedia::DISK)->delete($files);

        Log::info('Multimedia de producto eliminada.', ['product_id' => $media->product_id, 'media_id' => $media->id]);
    }
}
