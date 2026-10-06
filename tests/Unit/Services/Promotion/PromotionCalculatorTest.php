<?php

use App\Enums\PromotionType;
use App\Services\Promotion\PromotionCalculator;

it('calculates exact bounded integer discounts and final totals', function (int $subtotal, PromotionType $type, int $value, ?int $cap, int $discount, int $total) {
    $result = (new PromotionCalculator)->calculate($subtotal, $type, $value, $cap);

    expect($result->subtotalMinor)->toBe($subtotal);
    expect($result->discountMinor)->toBe($discount)->toBeInt();
    expect($result->totalMinor)->toBe($total)->toBeInt();
})->with([
    'percentage' => [15000, PromotionType::Percentage, 2000, null, 3000, 12000],
    'percentage cap' => [15000, PromotionType::Percentage, 2000, 2500, 2500, 12500],
    'fixed' => [8000, PromotionType::Fixed, 1500, null, 1500, 6500],
    'fixed cap' => [8000, PromotionType::Fixed, 1500, 500, 500, 7500],
    'fixed exceeds subtotal' => [100, PromotionType::Fixed, 1500, null, 100, 0],
    'full percentage' => [101, PromotionType::Percentage, 10000, null, 101, 0],
    'zero percentage subtotal' => [0, PromotionType::Percentage, 2000, null, 0, 0],
    'zero fixed subtotal' => [0, PromotionType::Fixed, 1500, null, 0, 0],
    'below half' => [1, PromotionType::Percentage, 4999, null, 0, 1],
    'exact half' => [1, PromotionType::Percentage, 5000, null, 1, 0],
    'above half' => [1, PromotionType::Percentage, 5001, null, 1, 0],
    'rounding across whole quotient' => [10005, PromotionType::Percentage, 1000, null, 1001, 9004],
    'one basis point at bigint maximum' => [PHP_INT_MAX, PromotionType::Percentage, 1, null, 922337203685478, 9222449699651090329],
    'twenty percent at bigint maximum' => [PHP_INT_MAX, PromotionType::Percentage, 2000, null, 1844674407370955161, 7378697629483820646],
    'full percentage at bigint maximum' => [PHP_INT_MAX, PromotionType::Percentage, 10000, null, PHP_INT_MAX, 0],
    'fixed at bigint maximum' => [PHP_INT_MAX, PromotionType::Fixed, PHP_INT_MAX, null, PHP_INT_MAX, 0],
    'large exact amount beyond IEEE integer precision' => [9007199254740993, PromotionType::Percentage, 5000, null, 4503599627370497, 4503599627370496],
]);

it('rejects invalid inputs before unsafe arithmetic', function (int $subtotal, PromotionType $type, int $value, ?int $cap) {
    expect(fn () => (new PromotionCalculator)->calculate($subtotal, $type, $value, $cap))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [-1, PromotionType::Fixed, 1, null],
    [1, PromotionType::Fixed, 0, null],
    [1, PromotionType::Fixed, -1, null],
    [1, PromotionType::Percentage, 0, null],
    [PHP_INT_MAX, PromotionType::Percentage, 10001, null],
    [PHP_INT_MAX, PromotionType::Percentage, PHP_INT_MAX, null],
    [1, PromotionType::Fixed, 1, 0],
    [1, PromotionType::Fixed, 1, -1],
]);
