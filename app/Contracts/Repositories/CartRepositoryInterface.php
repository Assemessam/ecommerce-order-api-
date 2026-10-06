<?php

namespace App\Contracts\Repositories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Promotion;
use App\Models\User;

interface CartRepositoryInterface
{
    public function findForUser(User $user): ?Cart;

    public function lockForUser(User $user): ?Cart;

    public function createOrLockForUser(User $user): Cart;

    public function findItem(Cart $cart, int $itemId): ?CartItem;

    public function findItemByProduct(Cart $cart, int $productId): ?CartItem;

    public function createItem(Cart $cart, int $productId, int $quantity): CartItem;

    public function updateQuantity(CartItem $item, int $quantity): void;

    public function deleteItem(CartItem $item): void;

    public function loadItems(Cart $cart): Cart;

    public function setPromotion(Cart $cart, ?Promotion $promotion): void;

    public function loadCheckoutItems(Cart $cart): Cart;

    public function clear(Cart $cart): void;
}
