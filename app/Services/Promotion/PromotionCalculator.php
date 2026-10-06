<?php

namespace App\Services\Promotion;

use App\DTOs\Promotion\DiscountCalculationResult;
use App\Enums\PromotionType;
use InvalidArgumentException;
use OverflowException;

class PromotionCalculator
{
    public function calculate(int $subtotalMinor, PromotionType $type, int $value, ?int $maximumDiscountMinor = null): DiscountCalculationResult
    {
        if ($subtotalMinor < 0 || $value < 1 || ($type === PromotionType::Percentage && $value > 10000)
            || ($maximumDiscountMinor !== null && $maximumDiscountMinor < 1)) {
            throw new InvalidArgumentException('Invalid discount calculation inputs.');
        }

        if ($type === PromotionType::Percentage) {
            /** Decomposition avoids subtotal * basisPoints overflow; the remainder product is below 100 million. */
            $whole = intdiv($subtotalMinor, 10000) * $value;
            $fraction = intdiv(($subtotalMinor % 10000) * $value + 5000, 10000);

            /** Valid basis points bound the result to subtotal; keep the addition explicitly checked. */
            if ($fraction > PHP_INT_MAX - $whole) {
                throw new OverflowException('The calculated discount exceeds the supported integer range.');
            }

            $discount = $whole + $fraction;
        } else {
            $discount = $value;
        }

        $discount = min($discount, $maximumDiscountMinor ?? $subtotalMinor, $subtotalMinor);

        return new DiscountCalculationResult($subtotalMinor, $discount, $subtotalMinor - $discount);
    }
}
