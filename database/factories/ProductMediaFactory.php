<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Crea solo la fila: los archivos no existen en el disco (las pruebas que los necesitan los suben de verdad).
 *
 * @extends Factory<ProductMedia>
 */
class ProductMediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'type' => ProductMedia::TYPE_IMAGE,
            'path' => Str::random(40).'.webp',
            'thumbnail_path' => Str::random(40).'.webp',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/webp',
            'size_bytes' => fake()->numberBetween(20_000, 500_000),
            'duration_seconds' => null,
            'position' => 0,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductMedia::TYPE_VIDEO,
            'path' => Str::random(40).'.mp4',
            'thumbnail_path' => null,
            'original_name' => fake()->word().'.mp4',
            'mime_type' => 'video/mp4',
            'duration_seconds' => 30,
        ]);
    }
}
