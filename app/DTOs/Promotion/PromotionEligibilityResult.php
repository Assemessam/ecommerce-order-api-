<?php

namespace App\DTOs\Promotion;

use App\Enums\PromotionIneligibilityReason;

readonly class PromotionEligibilityResult
{
    public function __construct(public ?PromotionIneligibilityReason $reason = null) {}

    public function isEligible(): bool
    {
        return $this->reason === null;
    }
}
