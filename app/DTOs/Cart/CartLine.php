<?php

namespace App\DTOs\Cart;

use App\Models\Product;

readonly class CartLine
{
    public function __construct(
        public int $id,
        public Product $product,
        public int $quantity,
        public int $subtotalMinor,
        public bool $isAvailable,
        public ?string $unavailableReason,
    ) {}
}
