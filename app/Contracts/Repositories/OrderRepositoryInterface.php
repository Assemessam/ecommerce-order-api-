<?php

namespace App\Contracts\Repositories;

use App\DTOs\Cart\CartLine;
use App\DTOs\Cart\CartView;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

interface OrderRepositoryInterface
{
    public function findByIdempotencyKey(User $user, string $key): ?Order;

    public function create(User $user, CartView $pricing, ?string $idempotencyKey): Order;

    /** @param Collection<int, CartLine> $lines */
    public function createItems(Order $order, Collection $lines): void;

    public function loadItems(Order $order): Order;
}
