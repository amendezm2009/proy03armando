<?php

namespace Database\Factories;

use App\Actions\GenerateBarcode;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('???-#####')),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(),
            'barcode' => null,
            'stock' => fake()->numberBetween(21, 200),
            'min_stock' => fake()->numberBetween(0, 20),
            'purchase_price' => fake()->randomFloat(2, 5, 500),
            'sale_price' => fn (array $attributes): float => round($attributes['purchase_price'] * 1.3, 2),
            'is_active' => true,
        ];
    }

    /**
     * Con un EAN-13 interno derivado de su id, como el que genera SaveProduct.
     */
    public function withBarcode(): static
    {
        return $this->afterCreating(
            fn (Product $product) => $product->forceFill(['barcode' => GenerateBarcode::forId($product->id)])->save()
        );
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['stock' => 0]);
    }

    /**
     * Con existencias, pero en el mínimo o por debajo de él.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => ['stock' => 3, 'min_stock' => 5]);
    }
}
