<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => [
                'amount_minor' => $this->price_minor,
                /** @var string */
                'currency' => config('catalogue.currency'),
            ],
            'stock_quantity' => $this->stock_quantity,
            /** @var 'active'|'inactive' */
            'status' => $this->status->value,
            /** @format date-time */
            'created_at' => $this->created_at?->toISOString(),
            /** @format date-time */
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
