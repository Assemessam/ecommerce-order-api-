<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

it('keeps users created before the administrator migration unprivileged', function () {
    $migration = require database_path('migrations/2026_10_06_173445_add_is_admin_to_users_table.php');
    $migration->down();
    $legacyCustomer = User::factory()->create();

    $migration->up();

    expect($legacyCustomer->fresh()->is_admin)->toBeFalse();
    expect(Gate::forUser($legacyCustomer->fresh())->allows('create', Product::class))->toBeFalse();
});
