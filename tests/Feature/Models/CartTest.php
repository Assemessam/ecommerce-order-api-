<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('enforces one cart per customer', function () {
    $cart = Cart::factory()->create();

    expect(fn () => DB::transaction(fn () => Cart::factory()->for($cart->user)->create()))
        ->toThrow(QueryException::class, 'carts_user_id_unique');

    $this->assertDatabaseCount('carts', 1);
});

it('enforces one product line per cart', function () {
    $item = CartItem::factory()->create();

    expect(fn () => DB::transaction(fn () => CartItem::factory()->for($item->cart)->for($item->product)->create()))
        ->toThrow(QueryException::class, 'cart_items_cart_id_product_id_unique');

    $this->assertDatabaseCount('cart_items', 1);
});

it('enforces positive non-null quantities at the database boundary', function (?int $quantity, string $constraint) {
    $cart = Cart::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('cart_items')->insert([
        'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity,
    ])))->toThrow(QueryException::class, $constraint);

    $this->assertDatabaseCount('cart_items', 0);
})->with([[0, 'cart_items_quantity_positive'], [-1, 'cart_items_quantity_positive'], [null, 'not-null constraint']]);

it('enforces item foreign keys', function (string $field, string $constraint) {
    $cart = Cart::factory()->create();
    $product = Product::factory()->create();
    $attributes = ['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1];
    $attributes[$field] = PHP_INT_MAX;

    expect(fn () => DB::transaction(fn () => DB::table('cart_items')->insert($attributes)))
        ->toThrow(QueryException::class, $constraint);

    $this->assertDatabaseCount('cart_items', 0);
})->with([['cart_id', 'cart_items_cart_id_foreign'], ['product_id', 'cart_items_product_id_foreign']]);

it('enforces cart customer foreign key integrity', function () {
    expect(fn () => DB::transaction(fn () => DB::table('carts')->insert(['user_id' => PHP_INT_MAX])))
        ->toThrow(QueryException::class, 'carts_user_id_foreign');

    $this->assertDatabaseCount('carts', 0);
});

it('cascades customer and cart deletion to cart items without changing products', function (bool $deleteCustomer) {
    $item = CartItem::factory()->create();
    $product = $item->product;
    $stock = $product->stock_quantity;

    if ($deleteCustomer) {
        $item->cart->user->delete();
    } else {
        $item->cart->delete();
    }

    $this->assertModelMissing($item);
    $this->assertModelMissing($item->cart);
    $this->assertModelExists($product);
    expect($product->fresh()->stock_quantity)->toBe($stock);
})->with([false, true]);

it('restricts deletion of products referenced by cart items', function () {
    $item = CartItem::factory()->create();

    expect(fn () => DB::transaction(fn () => $item->product->delete()))
        ->toThrow(QueryException::class, 'cart_items_product_id_foreign');

    $this->assertModelExists($item);
    $this->assertModelExists($item->product);
});

it('exposes the customer cart and inverse product relationships', function () {
    $item = CartItem::factory()->create(['quantity' => 3]);

    expect($item->cart->user->cart->id)->toBe($item->cart_id);
    expect($item->product->cartItems->sole()->id)->toBe($item->id);
    expect($item->cart->items->sole()->quantity)->toBe(3);
});
