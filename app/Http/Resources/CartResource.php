<?php

namespace App\Http\Resources;

use App\DTOs\Cart\CartView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartView */
class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'items' => CartItemResource::collection($this->items),
            'subtotal' => [
                'amount_minor' => $this->subtotalMinor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
            'promotion' => $this->promotion === null ? null : new PromotionResource($this->promotion),
            'promotion_eligibility' => $this->promotionEligibility === null ? null : [
                'is_eligible' => $this->promotionEligibility->isEligible(),
                /** @var 'PROMOTION_INACTIVE'|'PROMOTION_NOT_STARTED'|'PROMOTION_EXPIRED'|'PROMOTION_MINIMUM_NOT_MET'|'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED'|'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED'|'EMPTY_CART'|'INVALID_CART_STATE'|null */
                'reason' => $this->promotionEligibility->reason?->value,
                'message' => $this->promotionEligibility->reason?->message(),
            ],
            'estimated_discount' => [
                'amount_minor' => $this->estimatedDiscountMinor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
            'estimated_total' => [
                'amount_minor' => $this->estimatedTotalMinor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
        ];
    }
}
