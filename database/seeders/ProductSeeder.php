<?php

namespace Database\Seeders;

use App\Actions\SaveProduct;
use App\Models\Classification;
use App\Models\Product;
use App\Models\User;
use App\RoleName;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Productos de ejemplo. Se crean con SaveProduct, igual que desde el formulario, para que
     * tengan SKU, slug y código de barras generados. Es idempotente: no repite productos por nombre.
     */
    public function run(SaveProduct $save): void
    {
        $actor = User::role(RoleName::Admin->value)->first() ?? User::first();

        if ($actor === null) {
            return;
        }

        $products = [
            ['name' => 'Audífonos inalámbricos', 'classifications' => ['Electrónica'], 'stock' => 40, 'min_stock' => 10, 'purchase_price' => 450, 'sale_price' => 699],
            ['name' => 'Cargador USB-C 65 W', 'classifications' => ['Electrónica'], 'stock' => 8, 'min_stock' => 10, 'purchase_price' => 220, 'sale_price' => 349.5],
            ['name' => 'Bocina Bluetooth', 'classifications' => ['Electrónica', 'Hogar'], 'stock' => 25, 'min_stock' => 5, 'purchase_price' => 380, 'sale_price' => 599],
            ['name' => 'Sartén antiadherente 28 cm', 'classifications' => ['Hogar'], 'stock' => 60, 'min_stock' => 15, 'purchase_price' => 140, 'sale_price' => 259],
            ['name' => 'Juego de toallas', 'classifications' => ['Hogar'], 'stock' => 0, 'min_stock' => 10, 'purchase_price' => 180, 'sale_price' => 329],
            ['name' => 'Lámpara de escritorio LED', 'classifications' => ['Electrónica', 'Hogar'], 'stock' => 18, 'min_stock' => 6, 'purchase_price' => 160, 'sale_price' => 289],
            ['name' => 'Termo de acero 1 L', 'classifications' => ['Hogar'], 'stock' => 90, 'min_stock' => 20, 'purchase_price' => 95, 'sale_price' => 189],
            ['name' => 'Teclado mecánico', 'classifications' => ['Electrónica'], 'stock' => 14, 'min_stock' => 5, 'purchase_price' => 520, 'sale_price' => 849, 'is_active' => false],
            ['name' => 'Organizador de cocina', 'classifications' => [], 'stock' => 33, 'min_stock' => 8, 'purchase_price' => 70, 'sale_price' => 139],
            ['name' => 'Ratón ergonómico', 'classifications' => ['Electrónica'], 'stock' => 5, 'min_stock' => 5, 'purchase_price' => 210, 'sale_price' => 379],
        ];

        foreach ($products as $item) {
            if (Product::withTrashed()->where('name', $item['name'])->exists()) {
                continue;
            }

            $item['classification_ids'] = Classification::whereIn('name', $item['classifications'])->pluck('id')->all();
            unset($item['classifications']);

            $save->handle($item, $actor);
        }
    }
}
