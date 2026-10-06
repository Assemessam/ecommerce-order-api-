<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use InvalidArgumentException;

class EloquentPromotionRepository implements PromotionRepositoryInterface
{
    public function findByCode(string $normalizedCode): ?Promotion
    {
        return Promotion::query()->where('code', $normalizedCode)->first();
    }

    public function findByIdForUpdate(int $id): ?Promotion
    {
        return Promotion::query()->lockForUpdate()->find($id);
    }

    /** The caller must hold the promotion lock through eligibility checks and commit. */
    public function createRedemption(Order $order, string $redemptionKey): PromotionRedemption
    {
        if (! $order->exists || $order->promotion_id === null) {
            throw new InvalidArgumentException('A redemption requires a persisted order with a promotion.');
        }

        return PromotionRedemption::query()->create([
            'order_id' => $order->id,
            'promotion_id' => $order->promotion_id,
            'user_id' => $order->user_id,
            'redemption_key' => $redemptionKey,
            'discount_minor' => $order->discount_minor,
            'redeemed_at' => $order->placed_at,
        ]);
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
