<?php

namespace App\Contracts\Repositories;

use App\Models\Promotion;

interface PromotionRepositoryInterface
{
    public function findByCode(string $normalizedCode): ?Promotion;

    /** @return array{global: int, customer: int} */
    public function redemptionCounts(Promotion $promotion, int $customerId): array;
}
