<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('grants local administrator access repeatably without changing a password or issuing a token', function () {
    $user = User::factory()->create(['email' => 'local@example.test']);
    $password = $user->password;
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('admin:grant', ['email' => ' LOCAL@example.test '])->assertSuccessful();
    $this->artisan('admin:grant', ['email' => $user->email])->assertSuccessful();

    expect($user->fresh()->hasRole('administrator'))->toBeTrue();
    expect($user->fresh()->is_admin)->toBeFalse();
    expect($user->fresh()->password)->toBe($password);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('refuses administrator provisioning outside local development', function (string $environment) {
    $user = User::factory()->create();
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan('admin:grant', ['email' => $user->email])
        ->expectsOutputToContain('only available in the local environment')->assertFailed();

    expect($user->fresh()->is_admin)->toBeFalse();
    expect($user->fresh()->roles()->count())->toBe(0);
})->with(['production', 'staging', 'testing']);

it('requires an existing user rather than creating an account with a fixed password', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('admin:grant', ['email' => 'missing@example.test'])
        ->expectsOutputToContain('Register the user locally')->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

it('grants and revokes each canonical role idempotently without changing credentials or tokens', function (string $role) {
    $user = User::factory()->create(['email' => 'roles@example.test']);
    $password = $user->password;
    $token = $user->createToken('existing')->accessToken;
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('roles:grant', ['email' => ' ROLES@example.test ', 'role' => $role])->assertSuccessful();
    $this->artisan('roles:grant', ['email' => $user->email, 'role' => $role])->assertSuccessful();

    expect($user->fresh()->getRoleNames()->all())->toBe([$role]);
    expect($user->fresh()->password)->toBe($password);
    expect($user->fresh()->is_admin)->toBeFalse();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertModelExists($token);

    $this->artisan('roles:revoke', ['email' => $user->email, 'role' => $role])->assertSuccessful();
    $this->artisan('roles:revoke', ['email' => $user->email, 'role' => $role])->assertSuccessful();

    expect($user->fresh()->roles()->count())->toBe(0);
    expect($user->fresh()->password)->toBe($password);
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertModelExists($token);
})->with(['product_manager', 'promotion_manager', 'administrator']);

it('revokes one manager role while preserving the other manager capability', function (string $removed, string $remaining, string $permission) {
    $user = User::factory()->productManager()->promotionManager()->create();
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('roles:revoke', ['email' => $user->email, 'role' => $removed])->assertSuccessful();

    expect($user->fresh()->getRoleNames()->all())->toBe([$remaining]);
    expect($user->fresh()->can($permission))->toBeTrue();
})->with([
    ['product_manager', 'promotion_manager', 'promotions.create'],
    ['promotion_manager', 'product_manager', 'products.create'],
]);

it('refuses role grants and revocations outside local development', function (string $command, string $environment) {
    $user = User::factory()->productManager()->create();
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan($command, ['email' => $user->email, 'role' => 'promotion_manager'])->assertFailed();

    expect($user->fresh()->getRoleNames()->all())->toBe(['product_manager']);
})->with(['roles:grant', 'roles:revoke'])->with(['production', 'staging', 'testing']);

it('refuses unknown role names instead of creating a new role or assigning it', function (string $command, string $role) {
    $user = User::factory()->create();
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan($command, ['email' => $user->email, 'role' => $role])->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
    $this->assertDatabaseCount('roles', 3);
})->with(['roles:grant', 'roles:revoke'])->with(['customer', 'super_admin', 'products.create']);

it('refuses role operations for a missing account without creating credentials', function (string $command) {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan($command, ['email' => 'missing@example.test', 'role' => 'administrator'])->assertFailed();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['roles:grant', 'roles:revoke']);
