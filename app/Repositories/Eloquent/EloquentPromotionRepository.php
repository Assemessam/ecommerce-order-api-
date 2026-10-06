<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class EloquentPromotionRepository implements PromotionRepositoryInterface
{
    public function paginate(int $page, int $perPage, ?bool $isActive): LengthAwarePaginator
    {
        $promotions = Promotion::query()->withCount('redemptions');

        if ($isActive !== null) {
            $promotions->where('is_active', $isActive);
        }

        return $promotions->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(int $id): ?Promotion
    {
        return Promotion::query()->withCount('redemptions')->find($id);
    }

    public function create(array $attributes): Promotion
    {
        return Promotion::query()->create($attributes)->refresh()->loadCount('redemptions');
    }

    public function update(Promotion $promotion, array $attributes): Promotion
    {
        $promotion->update($attributes);

        return $promotion->refresh()->loadCount('redemptions');
    }

    public function maximumCustomerRedemptions(Promotion $promotion): int
    {
        $count = PromotionRedemption::query()->whereBelongsTo($promotion)
            ->selectRaw('COUNT(*) AS usage_count')->groupBy('user_id')
            ->orderByDesc('usage_count')->toBase()->first();

        return (int) ($count?->usage_count ?? 0);
    }

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
