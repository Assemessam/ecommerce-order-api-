<?php

namespace Database\Seeders;

use App\DTOs\Promotion\CreatePromotionData;
use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotion\PromotionAdministrationService;
use Carbon\CarbonImmutable;

class DemoPromotionSeeder extends DemoSeeder
{
    public function __construct(private PromotionAdministrationService $promotions) {}

    protected function seed(): void
    {
        $administrator = User::query()->where('email', 'demo-admin@example.test')->firstOrFail();
        $now = CarbonImmutable::now('UTC');

        /** @var list<array{code: string, type: string, value: int, minimum_cart_amount_minor?: int, maximum_discount_minor?: int, starts_at?: string, expires_at?: string, is_active?: bool, global_usage_limit?: int, per_customer_usage_limit?: int}> $promotions */
        $promotions = [
            ['code' => 'DEMO-V1-WELCOME10', 'type' => PromotionType::Percentage->value, 'value' => 1000],
            ['code' => 'DEMO-V1-SAVE5', 'type' => PromotionType::Fixed->value, 'value' => 500],
            ['code' => 'DEMO-V1-MINIMUM10', 'type' => PromotionType::Percentage->value, 'value' => 1000, 'minimum_cart_amount_minor' => 10000],
            ['code' => 'DEMO-V1-CAPPED20', 'type' => PromotionType::Percentage->value, 'value' => 2000, 'maximum_discount_minor' => 5000],
            ['code' => 'DEMO-V1-EXPIRED10', 'type' => PromotionType::Percentage->value, 'value' => 1000, 'starts_at' => $now->subDays(60)->format('Y-m-d H:i:s.uP'), 'expires_at' => $now->subDays(30)->format('Y-m-d H:i:s.uP')],
            ['code' => 'DEMO-V1-FUTURE10', 'type' => PromotionType::Percentage->value, 'value' => 1000, 'starts_at' => $now->addDays(30)->format('Y-m-d H:i:s.uP'), 'expires_at' => $now->addDays(60)->format('Y-m-d H:i:s.uP')],
            ['code' => 'DEMO-V1-INACTIVE10', 'type' => PromotionType::Percentage->value, 'value' => 1000, 'is_active' => false],
            ['code' => 'DEMO-V1-LIMITED5', 'type' => PromotionType::Fixed->value, 'value' => 500, 'global_usage_limit' => 2, 'per_customer_usage_limit' => 1],
            ['code' => 'DEMO-V1-HIGHMIN15', 'type' => PromotionType::Percentage->value, 'value' => 1500, 'minimum_cart_amount_minor' => 50000],
        ];

        foreach ($promotions as $promotion) {
            if (Promotion::query()->where('code', $promotion['code'])->exists()) {
                continue;
            }

            $createdPromotion = $this->promotions->createPromotion($administrator, CreatePromotionData::fromArray([
                'starts_at' => $now->subDays(60)->format('Y-m-d H:i:s.uP'),
                'expires_at' => $now->addDays(180)->format('Y-m-d H:i:s.uP'),
                'is_active' => true,
                ...$promotion,
            ]));
            $createdPromotion->forceFill(['created_at' => $now->subDays(60), 'updated_at' => $now->subDays(60)])->save();
        }
    }
}
