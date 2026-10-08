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
            /** @var 'placed'|'cancelled' */
            'status' => $this->status->value,
            'currency' => $this->currency,
            /** Item snapshots are included in detail, checkout and cancellation responses; omitted from order history. */
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => ['amount_minor' => $this->subtotal_minor, 'currency' => $this->currency],
            'discount' => ['amount_minor' => $this->discount_minor, 'currency' => $this->currency],
            'total' => ['amount_minor' => $this->total_minor, 'currency' => $this->currency],
            'promotion' => $this->promotion_code_snapshot === null ? null : [
                /** @var int */
                'id' => $this->promotion_id,
                /** @var string */
                'code' => $this->promotion_code_snapshot,
                /** @var 'percentage'|'fixed' */
                'type' => $this->promotion_type_snapshot->value,
                /** @var int */
                'value' => $this->promotion_value_snapshot,
                'maximum_discount' => $this->promotion_maximum_discount_minor_snapshot === null ? null : [
                    /** @var int */
                    'amount_minor' => $this->promotion_maximum_discount_minor_snapshot,
                    'currency' => $this->currency,
                ],
            ],
            /** @format date-time */
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            /** @format date-time */
            'inventory_restored_at' => $this->inventory_restored_at?->toISOString(),
            /**
             * @var string
             *
             * @format date-time
             */
            'placed_at' => $this->placed_at->toISOString(),
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => $this->created_at->toISOString(),
            /**
             * @var string
             *
             * @format date-time
             */
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
