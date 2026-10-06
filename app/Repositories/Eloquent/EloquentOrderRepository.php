<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\DTOs\Cart\CartLine;
use App\DTOs\Cart\CartView;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    /** @return LengthAwarePaginator<int, Order> */
    public function paginateForUser(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        return Order::query()->whereBelongsTo($user)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findForUser(User $user, int $id): ?Order
    {
        return Order::query()->whereBelongsTo($user)->with('items')->find($id);
    }

    public function lockForUser(User $user, int $id): ?Order
    {
        return Order::query()->whereBelongsTo($user)->lockForUpdate()->with('items')->find($id);
    }

    public function markCancelled(Order $order, CarbonInterface $cancelledAt): void
    {
        $order->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => $cancelledAt,
            'inventory_restored_at' => $cancelledAt,
        ]);
    }

    public function findByIdempotencyKey(User $user, string $key): ?Order
    {
        return Order::query()->whereBelongsTo($user)->where('idempotency_key', $key)->with('items')->first();
    }

    public function create(User $user, CartView $pricing, ?string $idempotencyKey): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Placed,
            'currency' => config('catalogue.currency'),
            'subtotal_minor' => $pricing->subtotalMinor,
            'discount_minor' => $pricing->estimatedDiscountMinor,
            'total_minor' => $pricing->estimatedTotalMinor,
            'promotion_id' => $pricing->promotion?->id,
            'promotion_code_snapshot' => $pricing->promotion?->code,
            'promotion_type_snapshot' => $pricing->promotion?->type,
            'promotion_value_snapshot' => $pricing->promotion?->value,
            'promotion_maximum_discount_minor_snapshot' => $pricing->promotion?->maximum_discount_minor,
            'idempotency_key' => $idempotencyKey,
            'placed_at' => now('UTC'),
        ]);
    }

    /** @param Collection<int, CartLine> $lines */
    public function createItems(Order $order, Collection $lines): void
    {
        $order->items()->createMany($lines->map(fn (CartLine $line): array => [
            'product_id' => $line->product->id,
            'product_name' => $line->product->name,
            'product_sku' => $line->product->sku,
            'quantity' => $line->quantity,
            'unit_price_minor' => $line->product->price_minor,
            'line_subtotal_minor' => $line->subtotalMinor,
        ])->all());
    }

    public function loadItems(Order $order): Order
    {
        return $order->refresh()->load('items');
    }
}
