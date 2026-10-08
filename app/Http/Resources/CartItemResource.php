<?php

namespace App\Http\Resources;

use App\DTOs\Cart\CartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartLine */
class CartItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => new ProductResource($this->product),
            'quantity' => $this->quantity,
            'unit_price' => [
                'amount_minor' => $this->product->price_minor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
            'line_subtotal' => [
                'amount_minor' => $this->subtotalMinor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
            'availability' => [
                'is_available' => $this->isAvailable,
                /** @var 'inactive'|'out_of_stock'|'insufficient_stock'|null */
                'reason' => $this->unavailableReason,
                'available_quantity' => $this->product->stock_quantity,
            ],
        ];
    }
}
