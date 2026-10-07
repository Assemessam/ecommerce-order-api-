<?php

use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

it('authorizes product capabilities from the assigned internal permissions', function (string $ability, array $roles, bool $allowed) {
    $user = User::factory()->create();
    $user->assignRole($roles);

    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(ProductPolicy::class);
    expect(Gate::forUser($user)->allows($ability, Product::class))->toBe($allowed);
})->with(['viewAny', 'view', 'create', 'update', 'adjustInventory'])->with([
    'customer' => [[], false],
    'product manager' => [['product_manager'], true],
    'promotion manager' => [['promotion_manager'], false],
    'administrator' => [['administrator'], true],
    'dual manager' => [['product_manager', 'promotion_manager'], true],
]);

it('denies product permissions for an unsaved customer', function () {
    expect(Gate::forUser(User::factory()->make())->allows('create', Product::class))->toBeFalse();
});

it('requires the individual product permission rather than a role name or legacy flag', function (string $ability, string $permission) {
    $user = User::factory()->create(['is_admin' => true]);

    expect(Gate::forUser($user)->allows($ability, Product::class))->toBeFalse();
    $user->givePermissionTo($permission);

    expect(Gate::forUser($user)->allows($ability, Product::class))->toBeTrue();
    expect($user->roles()->count())->toBe(0);
})->with([
    ['viewAny', 'products.view-admin'], ['view', 'products.view-admin'],
    ['create', 'products.create'], ['update', 'products.update'], ['adjustInventory', 'inventory.adjust'],
]);
