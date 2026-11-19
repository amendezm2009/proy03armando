<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SaveProduct
{
    /**
     * Crea o actualiza un producto con sus clasificaciones.
     *
     * - SKU y código de barras en blanco se generan al crear (PRD-000123 y EAN-13 interno derivados
     *   del id); al editar, en blanco significa "no cambiar".
     * - El slug se calcula una sola vez al crear y no cambia al renombrar (es parte de la URL y del QR).
     * - created_by / updated_by los pone esta acción con el usuario que actúa, nunca la petición.
     * - classification_ids reemplaza las clasificaciones; si no viene, se conservan.
     *
     * @param  array<string, mixed>  $data  Datos ya validados.
     */
    public function handle(array $data, User $actor, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $actor, $product): Product {
            $classificationIds = Arr::pull($data, 'classification_ids');

            foreach (['sku', 'barcode'] as $key) {
                if (array_key_exists($key, $data) && blank($data[$key])) {
                    unset($data[$key]);
                }
            }

            $isNew = $product === null;
            $needsSku = $isNew && ! isset($data['sku']);
            $product ??= new Product;
            $product->fill($data);

            if ($isNew) {
                $product->forceFill([
                    'slug' => $this->uniqueSlug((string) $product->name),
                    'created_by' => $actor->id,
                ]);

                if ($needsSku) {
                    // Marcador temporal único: el SKU definitivo necesita el id, que aún no existe.
                    $product->sku = 'TMP-'.Str::random(30);
                }
            }

            $product->updated_by = $actor->id;
            $product->save();

            if ($needsSku) {
                $product->sku = $this->generatedSku($product->id);
            }

            $product->barcode ??= GenerateBarcode::forId($product->id);
            $product->save();

            if ($classificationIds !== null) {
                $product->classifications()->sync($classificationIds);
            }

            Log::info($isNew ? 'product.created' : 'product.updated', [
                'product_id' => $product->id,
                'actor_id' => $actor->id,
            ]);

            return $product->load('classifications');
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'producto';
        $slug = $base;

        for ($suffix = 2; Product::withTrashed()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    private function generatedSku(int $id): string
    {
        $sku = sprintf('PRD-%06d', $id);

        // Si alguien ya registró a mano ese mismo SKU, se distingue con un sufijo.
        return Product::withTrashed()->where('sku', $sku)->exists()
            ? $sku.'-'.Str::upper(Str::random(4))
            : $sku;
    }
}
