<?php

namespace App\Services\Cart;

use App\DTOs\Cart\CartLine;
use App\DTOs\Cart\CartView;
use App\Enums\ProductStatus;
use App\Exceptions\Domain\CartTotalTooLargeException;
use App\Models\Cart;
use App\Models\Promotion;
use App\Services\Promotion\PromotionCalculator;
use App\Services\Promotion\PromotionService;

class CartPricingService
{
    public function __construct(
        private PromotionService $promotions,
        private PromotionCalculator $calculator,
    ) {}

    public function summarize(?Cart $cart, bool $includePromotion = true): CartView
    {
        $subtotal = 0;
        $lines = collect();

        foreach ($cart?->items ?? [] as $item) {
            $product = $item->product;

            if ($product->price_minor > intdiv(PHP_INT_MAX, $item->quantity)) {
                throw new CartTotalTooLargeException;
            }

            $lineSubtotal = $product->price_minor * $item->quantity;

            if ($lineSubtotal > PHP_INT_MAX - $subtotal) {
                throw new CartTotalTooLargeException;
            }

            $subtotal += $lineSubtotal;
            $reason = match (true) {
                $product->status !== ProductStatus::Active => 'inactive',
                $product->stock_quantity === 0 => 'out_of_stock',
                $item->quantity > $product->stock_quantity => 'insufficient_stock',
                default => null,
            };
            $lines->push(new CartLine($item->id, $product, $item->quantity, $lineSubtotal, $reason === null, $reason));
        }

        $view = new CartView($cart?->id, $lines, $subtotal, estimatedTotalMinor: $subtotal);

        return $includePromotion && $cart?->promotion !== null
            ? $this->withPromotion($view, $cart->promotion, $cart->user_id)
            : $view;
    }

    public function withPromotion(CartView $view, Promotion $promotion, int $customerId): CartView
    {
        $eligibility = $this->promotions->eligibility(
            $promotion, $customerId, $view->subtotalMinor,
            $view->items->isNotEmpty(),
            $view->items->every(fn (CartLine $line): bool => $line->isAvailable),
        );
        $calculation = $eligibility->isEligible()
            ? $this->calculator->calculate($view->subtotalMinor, $promotion->type, $promotion->value, $promotion->maximum_discount_minor)
            : null;

        return new CartView(
            $view->id, $view->items, $view->subtotalMinor, $promotion, $eligibility,
            $calculation?->discountMinor ?? 0, $calculation?->totalMinor ?? $view->subtotalMinor,
        );
    }
}
