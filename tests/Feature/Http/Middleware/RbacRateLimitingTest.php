<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('keeps the authenticated allowance when an existing token gains internal roles', function () {
    config(['rate-limits.policies.cart-read.attempts' => 1]);
    $user = User::factory()->create();
    $token = $user->createToken('before-grant')->plainTextToken;
    $this->withToken($token)->getJson('/api/cart')->assertOk();

    $user->assignRole('administrator');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/cart')->assertTooManyRequests();
});

it('shares one admin read allowance across added roles domains and tokens while isolating another user', function () {
    config(['rate-limits.policies.admin-read.attempts' => 1]);
    $first = User::factory()->productManager()->create();
    $second = User::factory()->promotionManager()->create();
    $token = $first->createToken('product-manager')->plainTextToken;
    $this->withToken($token)->getJson('/api/admin/products')->assertOk();

    $first->assignRole('promotion_manager');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/promotions')->assertTooManyRequests();
    $first->removeRole('product_manager');
    $this->app['auth']->forgetGuards();
    $this->withToken($first->createToken('after-revoke')->plainTextToken)->getJson('/api/admin/promotions')->assertTooManyRequests();
    $this->app['auth']->forgetGuards();

    $this->withToken($second->createToken('independent')->plainTextToken)->getJson('/api/admin/promotions')->assertOk();
});

it('does not reset a staff storefront allowance after every internal role is revoked', function () {
    config(['rate-limits.policies.cart-read.attempts' => 1]);
    $user = User::factory()->administrator()->create();
    $token = $user->createToken('before-revoke')->plainTextToken;
    $this->withToken($token)->getJson('/api/cart')->assertOk();

    $user->removeRole('administrator');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/cart')->assertTooManyRequests();
    expect($user->fresh()->roles()->count())->toBe(0);
});
