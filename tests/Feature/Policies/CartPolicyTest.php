<?php

use App\Models\Cart;
use App\Models\User;
use App\Policies\CartPolicy;
use Illuminate\Support\Facades\Gate;

it('allows the owner to view and modify the cart', function (string $ability) {
    $user = User::factory()->make(['id' => 11]);
    $cart = Cart::factory()->make(['id' => 21, 'user_id' => 11]);

    expect(Gate::getPolicyFor($cart))->toBeInstanceOf(CartPolicy::class);
    expect(Gate::forUser($user)->inspect($ability, $cart)->allowed())->toBeTrue();
})->with(['view', 'update']);

it('denies another customer with a non-enumerating 404', function (string $ability) {
    $user = User::factory()->make(['id' => 12]);
    $cart = Cart::factory()->make(['id' => 21, 'user_id' => 11]);

    $response = Gate::forUser($user)->inspect($ability, $cart);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
})->with(['view', 'update']);
