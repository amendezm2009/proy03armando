<?php

namespace Database\Seeders;

use App\Actions\SaveClassification;
use App\Models\Classification;
use Illuminate\Database\Seeder;

class ClassificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(SaveClassification $save): void
    {
        $manual = [
            [
                'name' => 'Electrónica',
                'description' => 'Dispositivos y accesorios electrónicos.',
                'color_badge' => '#0d6efd',
                'is_active' => true,
            ],
            [
                'name' => 'Hogar',
                'description' => 'Artículos para el hogar y la cocina.',
                'color_badge' => '#198754',
                'is_active' => true,
            ],
        ];

        $catalog = [
            ['name' => 'Ropa y calzado', 'description' => 'Prendas de vestir, zapatos y accesorios de moda.'],
            ['name' => 'Deportes', 'description' => 'Equipo y ropa para actividades deportivas.'],
            ['name' => 'Juguetes', 'description' => 'Juegos y juguetes para todas las edades.'],
            ['name' => 'Papelería', 'description' => 'Útiles escolares y de oficina.'],
            ['name' => 'Belleza y cuidado personal', 'description' => 'Productos de higiene, cosmética y bienestar.'],
            ['name' => 'Alimentos y bebidas', 'description' => 'Productos comestibles y bebidas envasadas.'],
            ['name' => 'Herramientas', 'description' => 'Herramientas manuales y eléctricas.'],
            ['name' => 'Mascotas', 'description' => 'Alimento y accesorios para mascotas.'],
        ];

        $generated = array_map(fn (array $item): array => $item + [
            'color_badge' => fake()->hexColor(),
            'is_active' => fake()->boolean(80),
        ], fake()->randomElements($catalog, 5));

        foreach (array_merge($manual, $generated) as $item) {
            $save->handle($item, Classification::firstWhere('name', $item['name']));
        }
    }
}
