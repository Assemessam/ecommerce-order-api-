<?php

namespace App\DTOs\Promotion;

readonly class DiscountCalculationResult
{
    public function __construct(
        public int $subtotalMinor,
        public int $discountMinor,
        public int $totalMinor,
    ) {}
}
