<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

it('creates the standard permission tables and canonical authorization on a fresh schema without privileged users', function () {
    $schema = require database_path('migrations/2026_10_07_135020_create_permission_tables.php');
    $bootstrap = require database_path('migrations/2026_10_07_135021_bootstrap_authorization.php');
    $schema->down();

    $schema->up();
    $bootstrap->up();

    foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();
    $this->assertDatabaseCount('roles', 3);
    $this->assertDatabaseCount('permissions', 7);
    $this->assertDatabaseCount('role_has_permissions', 14);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    $this->assertDatabaseCount('model_has_permissions', 0);
    $user = User::factory()->productManager()->create();
    expect(Gate::forUser($user)->allows('create', Product::class))->toBeTrue();
});

it('backfills only legacy administrators repeatably while preserving customer and manager boundaries', function () {
    $legacyAdministrator = User::factory()->create(['is_admin' => true]);
    $customer = User::factory()->create();
    $productManager = User::factory()->productManager()->create();
    $bootstrap = require database_path('migrations/2026_10_07_135021_bootstrap_authorization.php');

    $bootstrap->up();
    $bootstrap->up();

    expect($legacyAdministrator->fresh()->getRoleNames()->all())->toBe(['administrator']);
    expect($legacyAdministrator->fresh()->getAllPermissions()->count())->toBe(7);
    expect($legacyAdministrator->fresh()->is_admin)->toBeTrue();
    expect($customer->fresh()->roles()->count())->toBe(0);
    expect($customer->fresh()->is_admin)->toBeFalse();
    expect($productManager->fresh()->getRoleNames()->all())->toBe(['product_manager']);
    expect($productManager->fresh()->can('promotions.create'))->toBeFalse();
    $this->assertDatabaseCount('model_has_roles', 2);
    $this->assertDatabaseCount('model_has_permissions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('adds Administrator membership without replacing a legacy administrators independently assigned role', function () {
    $user = User::factory()->productManager()->create(['is_admin' => true]);
    $bootstrap = require database_path('migrations/2026_10_07_135021_bootstrap_authorization.php');

    $bootstrap->up();

    expect($user->fresh()->roles()->orderBy('name')->pluck('name')->all())->toBe(['administrator', 'product_manager']);
    expect($user->fresh()->can('promotions.create'))->toBeTrue();
    $this->assertDatabaseCount('model_has_roles', 2);
    expect(Role::query()->where('name', 'customer')->exists())->toBeFalse();
});
