<?php

use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

it('bootstraps exactly three internal roles and seven web permissions repeatably without privileged accounts', function () {
    $this->seed(AuthorizationSeeder::class);
    $this->seed(AuthorizationSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe(['administrator', 'product_manager', 'promotion_manager']);
    expect(Permission::query()->orderBy('name')->pluck('name')->all())->toBe([
        'inventory.adjust', 'products.create', 'products.update', 'products.view-admin',
        'promotions.create', 'promotions.update', 'promotions.view-admin',
    ]);
    expect(Role::query()->pluck('guard_name')->unique()->all())->toBe(['web']);
    expect(Permission::query()->pluck('guard_name')->unique()->all())->toBe(['web']);
    $this->assertDatabaseCount('role_has_permissions', 14);
    $this->assertDatabaseCount('model_has_roles', 0);
    $this->assertDatabaseCount('model_has_permissions', 0);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('assigns the exact approved permission set to each role', function (string $role, array $permissions) {
    $this->seed(AuthorizationSeeder::class);

    expect(Role::findByName($role, 'web')->permissions()->orderBy('name')->pluck('name')->all())->toBe($permissions);
})->with([
    'product manager' => ['product_manager', ['inventory.adjust', 'products.create', 'products.update', 'products.view-admin']],
    'promotion manager' => ['promotion_manager', ['promotions.create', 'promotions.update', 'promotions.view-admin']],
    'administrator' => ['administrator', ['inventory.adjust', 'products.create', 'products.update', 'products.view-admin',
        'promotions.create', 'promotions.update', 'promotions.view-admin']],
]);

it('repairs canonical role permissions and invalidates already warmed permission metadata', function () {
    $user = User::factory()->productManager()->create();
    $role = Role::findByName('product_manager', 'web');
    $role->givePermissionTo('promotions.create');
    expect($user->can('promotions.create'))->toBeTrue();

    $this->seed(AuthorizationSeeder::class);

    expect($user->can('promotions.create'))->toBeFalse();
    expect($user->can('products.create'))->toBeTrue();
});

it('leaves ordinary factories role-less and composes named internal role states without duplicates', function () {
    $customer = User::factory()->create();
    $productManager = User::factory()->productManager()->create();
    $promotionManager = User::factory()->promotionManager()->create();
    $administrator = User::factory()->administrator()->create();
    $dualManager = User::factory()->productManager()->promotionManager()->productManager()->create();

    expect($customer->getRoleNames()->all())->toBe([]);
    expect($productManager->getRoleNames()->all())->toBe(['product_manager']);
    expect($promotionManager->getRoleNames()->all())->toBe(['promotion_manager']);
    expect($administrator->getRoleNames()->all())->toBe(['administrator']);
    expect($dualManager->roles()->orderBy('name')->pluck('name')->all())->toBe(['product_manager', 'promotion_manager']);
    expect($dualManager->getAllPermissions()->pluck('name')->unique()->count())->toBe(7);
    expect($dualManager->fresh()->is_admin)->toBeFalse();
});

it('does not turn legacy flags into authority when the canonical seeder is rerun', function () {
    $legacyUser = User::factory()->create(['is_admin' => true]);
    $customer = User::factory()->create();

    $this->seed(AuthorizationSeeder::class);

    expect($legacyUser->fresh()->roles()->count())->toBe(0);
    expect($legacyUser->fresh()->can('products.create'))->toBeFalse();
    expect($customer->fresh()->roles()->count())->toBe(0);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
