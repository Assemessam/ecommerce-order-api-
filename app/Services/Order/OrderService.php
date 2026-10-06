<?php

namespace App\Services\Order;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\OrderStatus;
use App\Exceptions\Domain\InvalidOrderStatusException;
use App\Exceptions\Domain\InventoryRestorationOverflowException;
use App\Exceptions\Domain\OrderCancellationConflictException;
use App\Models\Order;
use App\Models\User;
use App\Services\Product\ProductCatalogueCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderService
{
    public function __construct(private OrderRepositoryInterface $orders, private ProductRepositoryInterface $products, private ProductCatalogueCache $catalogueCache) {}

    /** @return LengthAwarePaginator<int, Order> */
    public function listOrders(User $user, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->paginateForUser($user, $page, $perPage);
    }

    public function getOrder(User $user, string $id): Order
    {
        $order = $this->orders->findForUser($user, $this->orderId($id));

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$id]);
        }

        Gate::forUser($user)->authorize('view', $order);

        return $order;
    }

    public function cancelOrder(User $user, string $id): Order
    {
        $orderId = $this->orderId($id);

        try {
            return DB::transaction(function () use ($user, $orderId): Order {
                $order = $this->orders->lockForUser($user, $orderId);

                if ($order === null) {
                    throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
                }

                Gate::forUser($user)->authorize('cancel', $order);

                if ($order->status === OrderStatus::Cancelled) {
                    if ($order->cancelled_at === null || $order->inventory_restored_at === null || ! $order->cancelled_at->equalTo($order->inventory_restored_at)) {
                        throw new OrderCancellationConflictException;
                    }

                    return $order;
                }

                if ($order->status !== OrderStatus::Placed || $order->inventory_restored_at !== null || $order->cancelled_at !== null) {
                    throw new InvalidOrderStatusException;
                }

                if ($order->items->isEmpty()) {
                    throw new OrderCancellationConflictException;
                }

                $products = $this->products->lockByIds($order->items->pluck('product_id')->all());

                foreach ($order->items->sortBy('product_id') as $item) {
                    $product = $products->get($item->product_id);

                    if ($product === null) {
                        throw new OrderCancellationConflictException;
                    }

                    if ($product->stock_quantity > PHP_INT_MAX - $item->quantity || ! $this->products->restoreStock($product, $item->quantity)) {
                        throw new InventoryRestorationOverflowException;
                    }
                }

                $this->orders->markCancelled($order, now('UTC'));
                $this->catalogueCache->invalidateAfterCommit();

                return $this->orders->loadItems($order);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new OrderCancellationConflictException;
            }

            throw $exception;
        }
    }

    private function orderId(string $id): int
    {
        $orderId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($orderId === false) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$id]);
        }

        return $orderId;
    }
}
