<?php

namespace App\Services\Promotion;

use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\DTOs\Promotion\PromotionEligibilityResult;
use App\Enums\PromotionIneligibilityReason as Reason;
use App\Exceptions\Domain\PromotionNotEligibleException;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PromotionService
{
    public function __construct(private PromotionRepositoryInterface $promotions) {}

    public function resolveCode(string $code): Promotion
    {
        $promotion = $this->promotions->findByCode(Promotion::normalizeCode($code));

        if ($promotion === null) {
            throw new PromotionNotEligibleException(Reason::Unknown);
        }

        return $promotion;
    }

    public function eligibility(Promotion $promotion, int $customerId, int $subtotalMinor, bool $hasItems, bool $allItemsPurchasable): PromotionEligibilityResult
    {
        if ($subtotalMinor < 0) {
            throw new InvalidArgumentException('The subtotal must be non-negative.');
        }

        $now = CarbonImmutable::now('UTC');
        $reason = match (true) {
            ! $hasItems => Reason::EmptyCart,
            ! $allItemsPurchasable => Reason::InvalidCartState,
            ! $promotion->is_active => Reason::Inactive,
            $promotion->starts_at !== null && $now->lt($promotion->starts_at->utc()) => Reason::NotStarted,
            $promotion->expires_at !== null && $now->gte($promotion->expires_at->utc()) => Reason::Expired,
            $subtotalMinor < $promotion->minimum_cart_amount_minor => Reason::MinimumNotMet,
            default => null,
        };

        if ($reason !== null) {
            return new PromotionEligibilityResult($reason);
        }

        if ($promotion->global_usage_limit !== null || $promotion->per_customer_usage_limit !== null) {
            $counts = $this->promotions->redemptionCounts($promotion, $customerId);
            $reason = match (true) {
                $promotion->global_usage_limit !== null && $counts['global'] >= $promotion->global_usage_limit => Reason::GlobalLimitReached,
                $promotion->per_customer_usage_limit !== null && $counts['customer'] >= $promotion->per_customer_usage_limit => Reason::CustomerLimitReached,
                default => null,
            };
        }

        return new PromotionEligibilityResult($reason);
    }
}
