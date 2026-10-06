<?php

namespace App\Services\Cart;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Cart\CartView;
use App\Enums\ProductStatus;
use App\Exceptions\Domain\CartConflictException;
use App\Exceptions\Domain\InactiveProductException;
use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private ProductRepositoryInterface $products,
        private CartPricingService $pricing,
    ) {}

    public function getCart(User $user): CartView
    {
        $cart = $this->carts->findForUser($user);

        if ($cart !== null) {
            Gate::forUser($user)->authorize('view', $cart);
        }

        return $this->pricing->summarize($cart);
    }

    public function addItem(User $user, int $productId, int $quantity): CartView
    {
        $this->validateQuantity($quantity);

        return $this->mutate(function () use ($user, $productId, $quantity): CartView {
            $cart = $this->carts->createOrLockForUser($user);
            Gate::forUser($user)->authorize('update', $cart);
            $product = $this->eligibleProduct($productId);
            $item = $this->carts->findItemByProduct($cart, $productId);
            $existingQuantity = $item?->quantity ?? 0;

            /** Subtraction avoids overflowing an accumulated bigint quantity before checking stock. */
            if ($existingQuantity > $product->stock_quantity || $quantity > $product->stock_quantity - $existingQuantity) {
                throw new InsufficientStockException($productId, $product->stock_quantity);
            }

            if ($item === null) {
                $this->carts->createItem($cart, $productId, $quantity);
            } else {
                $this->carts->updateQuantity($item, $existingQuantity + $quantity);
            }

            return $this->pricing->summarize($this->carts->loadItems($cart));
        });
    }

    public function updateItem(User $user, string $itemId, int $quantity): CartView
    {
        $this->validateQuantity($quantity);
        $id = $this->itemId($itemId);

        return $this->mutate(function () use ($user, $id, $quantity): CartView {
            $cart = $this->lockOwnedCart($user);
            $item = $this->carts->findItem($cart, $id);

            if ($item === null) {
                throw (new ModelNotFoundException)->setModel(CartItem::class, [$id]);
            }

            $product = $this->eligibleProduct($item->product_id);

            if ($quantity > $product->stock_quantity) {
                throw new InsufficientStockException($product->id, $product->stock_quantity);
            }

            $this->carts->updateQuantity($item, $quantity);

            return $this->pricing->summarize($this->carts->loadItems($cart));
        });
    }

    public function removeItem(User $user, string $itemId): void
    {
        $id = $this->itemId($itemId);

        $this->mutate(function () use ($user, $id): void {
            $cart = $this->lockOwnedCart($user);
            $item = $this->carts->findItem($cart, $id);

            if ($item === null) {
                throw (new ModelNotFoundException)->setModel(CartItem::class, [$id]);
            }

            $this->carts->deleteItem($item);
        });
    }

    private function lockOwnedCart(User $user): Cart
    {
        $cart = $this->carts->lockForUser($user);

        if ($cart === null) {
            throw (new ModelNotFoundException)->setModel(Cart::class);
        }

        Gate::forUser($user)->authorize('update', $cart);

        return $cart;
    }

    private function validateQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => ['The quantity field must be at least 1.']]);
        }
    }

    private function itemId(string $itemId): int
    {
        $id = filter_var($itemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw (new ModelNotFoundException)->setModel(CartItem::class, [$itemId]);
        }

        return $id;
    }

    private function eligibleProduct(int $productId): Product
    {
        $product = $this->products->findByIdForUpdate($productId);

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        if ($product->status !== ProductStatus::Active) {
            throw new InactiveProductException;
        }

        return $product;
    }

    /** @param Closure(): mixed $operation */
    private function mutate(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation, attempts: 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new CartConflictException;
            }

            throw $exception;
        }
    }
}
