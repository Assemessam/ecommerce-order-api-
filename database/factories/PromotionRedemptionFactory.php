<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionRedemption> */
class PromotionRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'user_id' => User::factory(),
            'redemption_key' => fake()->uuid(),
            'discount_minor' => 500,
            'redeemed_at' => now('UTC'),
        ];
    }
}
