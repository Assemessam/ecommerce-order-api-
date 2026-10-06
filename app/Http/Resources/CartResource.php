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
                'currency' => config('catalogue.currency'),
            ],
            'promotion' => $this->promotion === null ? null : new PromotionResource($this->promotion),
            'promotion_eligibility' => $this->promotionEligibility === null ? null : [
                'is_eligible' => $this->promotionEligibility->isEligible(),
                'reason' => $this->promotionEligibility->reason?->value,
                'message' => $this->promotionEligibility->reason?->message(),
            ],
            'estimated_discount' => [
                'amount_minor' => $this->estimatedDiscountMinor,
                'currency' => config('catalogue.currency'),
            ],
            'estimated_total' => [
                'amount_minor' => $this->estimatedTotalMinor,
                'currency' => config('catalogue.currency'),
            ],
        ];
    }
}
