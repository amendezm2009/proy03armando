<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fija el orden de la multimedia de un producto. La lista debe contener exactamente los ids de sus archivos,
 * sin repetir ni faltar ninguno; la posición de cada uno pasa a ser su lugar en la lista (desde 0).
 *
 * USO: app(ReorderProductMedia::class)->handle($product, [12, 9, 15]);
 */
class ReorderProductMedia
{
    /**
     * @param  array<int, mixed>  $orderedIds
     *
     * @throws ValidationException
     */
    public function handle(Product $product, array $orderedIds): void
    {
        $ids = array_map('intval', $orderedIds);

        DB::transaction(function () use ($product, $ids): void {
            $current = $product->media()->lockForUpdate()->pluck('id')->all();

            if (count($ids) !== count($current) || count(array_unique($ids)) !== count($ids) || array_diff($ids, $current) !== []) {
                throw ValidationException::withMessages(['ids' => __('validation.media_order')]);
            }

            foreach ($ids as $position => $id) {
                ProductMedia::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
