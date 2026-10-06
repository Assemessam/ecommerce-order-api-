<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Promotion;
use App\Models\User;

class EloquentCartRepository implements CartRepositoryInterface
{
    public function findForUser(User $user): ?Cart
    {
        return Cart::query()->whereBelongsTo($user)->with(['items.product', 'promotion'])->first();
    }

    public function lockForUser(User $user): ?Cart
    {
        return Cart::query()->whereBelongsTo($user)->lockForUpdate()->first();
    }

    public function createOrLockForUser(User $user): Cart
    {
        $cart = $this->lockForUser($user);

        if ($cart !== null) {
            return $cart;
        }

        /** PostgreSQL ON CONFLICT DO NOTHING waits for a competing insert without aborting the transaction. */
        Cart::query()->insertOrIgnore([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Cart::query()->whereBelongsTo($user)->lockForUpdate()->firstOrFail();
    }

    public function findItem(Cart $cart, int $itemId): ?CartItem
    {
        return $cart->items()->find($itemId);
    }

    public function findItemByProduct(Cart $cart, int $productId): ?CartItem
    {
        return $cart->items()->where('product_id', $productId)->first();
    }

    public function createItem(Cart $cart, int $productId, int $quantity): CartItem
    {
        return $cart->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        $item->update(['quantity' => $quantity]);
    }

    public function deleteItem(CartItem $item): void
    {
        $item->delete();
    }

    public function loadItems(Cart $cart): Cart
    {
        return $cart->load(['items.product', 'promotion']);
    }

    public function setPromotion(Cart $cart, ?Promotion $promotion): void
    {
        $cart->promotion()->associate($promotion);
        $cart->save();
    }

    public function loadCheckoutItems(Cart $cart): Cart
    {
        return $cart->load('items');
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $this->setPromotion($cart, null);
    }
}
