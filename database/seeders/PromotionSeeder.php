<?php

namespace Database\Seeders;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Database\Seeder;

class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $promotions = [
            ['code' => 'SUMMER20', 'type' => PromotionType::Percentage, 'value' => 2000, 'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 5000],
            ['code' => 'WELCOME10', 'type' => PromotionType::Percentage, 'value' => 1000],
            ['code' => 'SAVE15', 'type' => PromotionType::Fixed, 'value' => 1500],
            ['code' => 'LIMITED5', 'type' => PromotionType::Fixed, 'value' => 500, 'global_usage_limit' => 5, 'per_customer_usage_limit' => 1],
            ['code' => 'INACTIVE10', 'type' => PromotionType::Percentage, 'value' => 1000, 'is_active' => false],
            ['code' => 'FUTURE10', 'type' => PromotionType::Percentage, 'value' => 1000, 'starts_at' => now('UTC')->addMonth(), 'expires_at' => now('UTC')->addMonths(2)],
            ['code' => 'EXPIRED10', 'type' => PromotionType::Percentage, 'value' => 1000, 'starts_at' => now('UTC')->subMonths(2), 'expires_at' => now('UTC')->subMonth()],
        ];

        foreach ($promotions as $promotion) {
            Promotion::query()->firstOrCreate(['code' => $promotion['code']], $promotion);
        }
    }
}
