<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Wireless Keyboard', 'Travel Mug', 'Desk Lamp', 'Canvas Backpack', 'USB-C Cable']),
            'sku' => fake()->unique()->bothify('PRD-????-########'),
            'description' => fake()->sentence(),
            'price_minor' => fake()->numberBetween(500, 25000),
            'stock_quantity' => fake()->numberBetween(1, 100),
            'status' => ProductStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ProductStatus::Inactive]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => ['stock_quantity' => 0]);
    }
}
