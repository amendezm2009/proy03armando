<?php

use App\Models\Classification;
use App\Models\Product;
use App\Models\User;

describe('búsqueda de texto', function () {
    it('trata % y _ como caracteres literales en los productos', function () {
        Product::factory()->create(['name' => 'Oferta 100% algodón']);
        Product::factory()->create(['name' => 'Oferta 1000 piezas']);
        Product::factory()->create(['name' => 'Mesa_pequeña']);
        Product::factory()->create(['name' => 'Mesa grande']);

        expect(Product::filter(['search' => '100%'])->pluck('name')->all())->toBe(['Oferta 100% algodón'])
            ->and(Product::filter(['search' => '%'])->pluck('name')->all())->toBe(['Oferta 100% algodón'])
            ->and(Product::filter(['search' => 'a_p'])->pluck('name')->all())->toBe(['Mesa_pequeña'])
            ->and(Product::filter(['search' => '_'])->pluck('name')->all())->toBe(['Mesa_pequeña']);
    });

    it('sigue buscando por nombre, SKU y código de barras sin importar las mayúsculas', function () {
        $product = Product::factory()->create(['name' => 'Lámpara Solar', 'sku' => 'LAM-001']);

        expect(Product::filter(['search' => 'solar'])->pluck('id')->all())->toBe([$product->id])
            ->and(Product::filter(['search' => 'lam-0'])->pluck('id')->all())->toBe([$product->id])
            ->and(Product::filter(['search' => $product->barcode])->pluck('id')->all())->toBe([$product->id]);
    });

    it('trata % y _ como literales en usuarios y clasificaciones', function () {
        User::factory()->create(['name' => 'Ana 100%']);
        User::factory()->create(['name' => 'Ana Pérez']);
        Classification::factory()->create(['name' => 'Ofertas 50%']);
        Classification::factory()->create(['name' => 'Ofertas todo el año']);

        expect(User::filter(['search' => '%'])->pluck('name')->all())->toBe(['Ana 100%'])
            ->and(Classification::filter(['search' => '%'])->pluck('name')->all())->toBe(['Ofertas 50%']);
    });

    it('una comilla o un intento de inyección se busca como texto y no rompe la consulta', function () {
        Product::factory()->create(['name' => 'Silla normal']);

        expect(Product::filter(['search' => "' OR 1=1 --"])->count())->toBe(0)
            ->and(Product::filter(['search' => '!'])->count())->toBe(0)
            ->and(Product::count())->toBe(1);
    });
});
