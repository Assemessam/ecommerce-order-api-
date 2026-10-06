<?php

namespace App\Http\Resources;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Promotion */
class PromotionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'percentage_basis_points' => $this->type === PromotionType::Percentage ? $this->value : null,
            'fixed_amount' => $this->type === PromotionType::Fixed
                ? ['amount_minor' => $this->value, 'currency' => config('catalogue.currency')] : null,
            'minimum_cart_amount' => ['amount_minor' => $this->minimum_cart_amount_minor, 'currency' => config('catalogue.currency')],
            'maximum_discount' => $this->maximum_discount_minor === null ? null
                : ['amount_minor' => $this->maximum_discount_minor, 'currency' => config('catalogue.currency')],
            'starts_at' => $this->starts_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
