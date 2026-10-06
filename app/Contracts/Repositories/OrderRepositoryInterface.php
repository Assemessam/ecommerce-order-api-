<?php

namespace App\Contracts\Repositories;

use App\DTOs\Cart\CartLine;
use App\DTOs\Cart\CartView;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OrderRepositoryInterface
{
    /** @return LengthAwarePaginator<int, Order> */
    public function paginateForUser(User $user, int $page, int $perPage): LengthAwarePaginator;

    public function findForUser(User $user, int $id): ?Order;

    public function lockForUser(User $user, int $id): ?Order;

    public function markCancelled(Order $order, CarbonInterface $cancelledAt): void;

    public function findByIdempotencyKey(User $user, string $key): ?Order;

    public function create(User $user, CartView $pricing, ?string $idempotencyKey): Order;

    /** @param Collection<int, CartLine> $lines */
    public function createItems(Order $order, Collection $lines): void;

    public function loadItems(Order $order): Order;
}
