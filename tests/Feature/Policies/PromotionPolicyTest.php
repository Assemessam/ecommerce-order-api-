<?php

use App\Models\Promotion;
use App\Models\User;
use App\Policies\PromotionPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

it('authorizes promotion capabilities from the assigned internal permissions', function (string $ability, array $roles, bool $allowed) {
    $user = User::factory()->create();
    $user->assignRole($roles);

    expect(Gate::getPolicyFor(Promotion::class))->toBeInstanceOf(PromotionPolicy::class);
    expect(Gate::forUser($user)->allows($ability, Promotion::class))->toBe($allowed);
})->with(['viewAny', 'view', 'create', 'update'])->with([
    'customer' => [[], false],
    'product manager' => [['product_manager'], false],
    'promotion manager' => [['promotion_manager'], true],
    'administrator' => [['administrator'], true],
    'dual manager' => [['product_manager', 'promotion_manager'], true],
]);

it('denies promotion permissions for an unsaved customer', function () {
    expect(Gate::forUser(User::factory()->make())->allows('create', Promotion::class))->toBeFalse();
});

it('requires the individual promotion permission rather than a role name or legacy flag', function (string $ability, string $permission) {
    $user = User::factory()->create(['is_admin' => true]);

    expect(Gate::forUser($user)->allows($ability, Promotion::class))->toBeFalse();
    $user->givePermissionTo($permission);

    expect(Gate::forUser($user)->allows($ability, Promotion::class))->toBeTrue();
    expect($user->roles()->count())->toBe(0);
})->with([
    ['viewAny', 'promotions.view-admin'], ['view', 'promotions.view-admin'],
    ['create', 'promotions.create'], ['update', 'promotions.update'],
]);
