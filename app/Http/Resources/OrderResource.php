<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => ['amount_minor' => $this->subtotal_minor, 'currency' => $this->currency],
            'discount' => ['amount_minor' => $this->discount_minor, 'currency' => $this->currency],
            'total' => ['amount_minor' => $this->total_minor, 'currency' => $this->currency],
            'promotion' => $this->promotion_code_snapshot === null ? null : [
                'id' => $this->promotion_id,
                'code' => $this->promotion_code_snapshot,
                'type' => $this->promotion_type_snapshot->value,
                'value' => $this->promotion_value_snapshot,
                'maximum_discount' => $this->promotion_maximum_discount_minor_snapshot === null ? null : [
                    'amount_minor' => $this->promotion_maximum_discount_minor_snapshot, 'currency' => $this->currency,
                ],
            ],
            'placed_at' => $this->placed_at->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
