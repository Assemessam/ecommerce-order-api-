<?php

namespace App\Contracts\Repositories;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;

interface PromotionRepositoryInterface
{
    public function findByCode(string $normalizedCode): ?Promotion;

    /** @return array{global: int, customer: int} */
    public function redemptionCounts(Promotion $promotion, int $customerId): array;

    public function findByIdForUpdate(int $id): ?Promotion;

    public function createRedemption(Order $order, string $redemptionKey): PromotionRedemption;
}
