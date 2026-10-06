<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Models\Promotion;
use App\Models\PromotionRedemption;

class EloquentPromotionRepository implements PromotionRepositoryInterface
{
    public function findByCode(string $normalizedCode): ?Promotion
    {
        return Promotion::query()->where('code', $normalizedCode)->first();
    }

    /** @return array{global: int, customer: int} */
    public function redemptionCounts(Promotion $promotion, int $customerId): array
    {
        $counts = PromotionRedemption::query()->whereBelongsTo($promotion)
            ->selectRaw('COUNT(*) AS global_count, COUNT(*) FILTER (WHERE user_id = ?) AS customer_count', [$customerId])
            ->toBase()->first();

        return ['global' => (int) $counts->global_count, 'customer' => (int) $counts->customer_count];
    }
}
