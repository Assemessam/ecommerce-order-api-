<?php

namespace App\DTOs\Checkout;

use App\Models\Order;

readonly class CheckoutResult
{
    public function __construct(public Order $order, public bool $isReplay = false) {}
}
