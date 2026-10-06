<?php

namespace App\Contracts\Repositories;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PromotionRepositoryInterface
{
    /** @return LengthAwarePaginator<int, Promotion> */
    public function paginate(int $page, int $perPage, ?bool $isActive): LengthAwarePaginator;

    public function findById(int $id): ?Promotion;

    /** @param array{code: string, type: string, value: int, minimum_cart_amount_minor?: int, maximum_discount_minor?: ?int, starts_at?: ?string, expires_at?: ?string, global_usage_limit?: ?int, per_customer_usage_limit?: ?int, is_active?: bool} $attributes */
    public function create(array $attributes): Promotion;

    /** @param array{code?: string, type?: string, value?: int, minimum_cart_amount_minor?: int, maximum_discount_minor?: ?int, starts_at?: ?string, expires_at?: ?string, global_usage_limit?: ?int, per_customer_usage_limit?: ?int, is_active?: bool} $attributes */
    public function update(Promotion $promotion, array $attributes): Promotion;

    public function maximumCustomerRedemptions(Promotion $promotion): int;

    public function findByCode(string $normalizedCode): ?Promotion;

    /** @return array{global: int, customer: int} */
    public function redemptionCounts(Promotion $promotion, int $customerId): array;

    public function findByIdForUpdate(int $id): ?Promotion;

    public function createRedemption(Order $order, string $redemptionKey): PromotionRedemption;
}
