<?php

namespace App\DTOs\Cart;

use App\DTOs\Promotion\PromotionEligibilityResult;
use App\Models\Promotion;
use Illuminate\Support\Collection;

readonly class CartView
{
    /** @param Collection<int, CartLine> $items */
    public function __construct(
        public ?int $id,
        public Collection $items,
        public int $subtotalMinor,
        public ?Promotion $promotion = null,
        public ?PromotionEligibilityResult $promotionEligibility = null,
        public int $estimatedDiscountMinor = 0,
        public int $estimatedTotalMinor = 0,
    ) {}
}
