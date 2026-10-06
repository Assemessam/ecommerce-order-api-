<?php

namespace App\Enums;

enum PromotionIneligibilityReason: string
{
    case Unknown = 'PROMOTION_NOT_FOUND';
    case Inactive = 'PROMOTION_INACTIVE';
    case NotStarted = 'PROMOTION_NOT_STARTED';
    case Expired = 'PROMOTION_EXPIRED';
    case MinimumNotMet = 'PROMOTION_MINIMUM_NOT_MET';
    case GlobalLimitReached = 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED';
    case CustomerLimitReached = 'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED';
    case EmptyCart = 'EMPTY_CART';
    case InvalidCartState = 'INVALID_CART_STATE';

    public function message(): string
    {
        return match ($this) {
            self::Unknown => 'The promotion code was not found.',
            self::Inactive => 'The promotion is inactive.',
            self::NotStarted => 'The promotion has not started yet.',
            self::Expired => 'The promotion has expired.',
            self::MinimumNotMet => 'The cart subtotal does not meet the promotion minimum.',
            self::GlobalLimitReached => 'The promotion global usage limit has been reached.',
            self::CustomerLimitReached => 'The promotion customer usage limit has been reached.',
            self::EmptyCart => 'The cart must contain at least one item.',
            self::InvalidCartState => 'All cart items must be currently purchasable to apply a promotion.',
        };
    }
}
