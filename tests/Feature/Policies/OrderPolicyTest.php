<?php

use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;

it('allows the order owner to view and cancel', function (string $ability) {
    $user = User::factory()->make(['id' => 11]);
    $order = Order::factory()->make(['user_id' => 11]);
    expect(Gate::getPolicyFor($order))->toBeInstanceOf(OrderPolicy::class);
    expect(Gate::forUser($user)->inspect($ability, $order)->allowed())->toBeTrue();
})->with(['view', 'cancel']);

it('denies another customer with a non-enumerating 404', function (string $ability) {
    $user = User::factory()->make(['id' => 12]);
    $order = Order::factory()->make(['user_id' => 11]);
    $response = Gate::forUser($user)->inspect($ability, $order);
    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
})->with(['view', 'cancel']);
