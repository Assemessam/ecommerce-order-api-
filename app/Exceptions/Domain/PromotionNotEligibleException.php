<?php

namespace App\Exceptions\Domain;

use App\Enums\PromotionIneligibilityReason;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class PromotionNotEligibleException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly PromotionIneligibilityReason $reason)
    {
        parent::__construct($reason->message());
    }
}
