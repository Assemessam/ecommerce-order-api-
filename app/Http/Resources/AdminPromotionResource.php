<?php

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Promotion */
class AdminPromotionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            /** @var 'percentage'|'fixed' */
            'type' => $this->type->value,
            'value' => $this->value,
            'minimum_cart_amount_minor' => $this->minimum_cart_amount_minor,
            'maximum_discount_minor' => $this->maximum_discount_minor,
            /** @format date-time */
            'starts_at' => $this->starts_at?->toISOString(),
            /** @format date-time */
            'expires_at' => $this->expires_at?->toISOString(),
            'global_usage_limit' => $this->global_usage_limit,
            'per_customer_usage_limit' => $this->per_customer_usage_limit,
            'is_active' => $this->is_active,
            /** @var int */
            'redemptions_count' => $this->redemptions_count,
            /** @format date-time */
            'created_at' => $this->created_at?->toISOString(),
            /** @format date-time */
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
