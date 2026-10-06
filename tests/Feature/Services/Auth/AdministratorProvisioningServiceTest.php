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

    expect($user->fresh()->is_admin)->toBeTrue();
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
})->with(['production', 'staging', 'testing']);

it('requires an existing user rather than creating an account with a fixed password', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('admin:grant', ['email' => 'missing@example.test'])
        ->expectsOutputToContain('Register the user locally')->assertFailed();

    $this->assertDatabaseCount('users', 0);
});
