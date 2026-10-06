<?php

namespace Database\Factories;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PROMO-????-########'),
            'type' => PromotionType::Percentage,
            'value' => 2000,
            'minimum_cart_amount_minor' => 0,
            'maximum_discount_minor' => null,
            'starts_at' => null,
            'expires_at' => null,
            'global_usage_limit' => null,
            'per_customer_usage_limit' => null,
            'is_active' => true,
        ];
    }

    public function fixed(int $amountMinor = 1500): static
    {
        return $this->state(fn (array $attributes): array => ['type' => PromotionType::Fixed, 'value' => $amountMinor]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function future(): static
    {
        return $this->state(fn (array $attributes): array => ['starts_at' => now('UTC')->addDay(), 'expires_at' => now('UTC')->addMonth()]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['starts_at' => now('UTC')->subMonth(), 'expires_at' => now('UTC')->subDay()]);
    }

    public function limited(int $globalLimit = 5, int $customerLimit = 1): static
    {
        return $this->state(fn (array $attributes): array => ['global_usage_limit' => $globalLimit, 'per_customer_usage_limit' => $customerLimit]);
    }
}
