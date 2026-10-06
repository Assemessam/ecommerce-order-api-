<?php

use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\Gate;

it('allows administrator catalogue permissions and denies default customers', function (string $ability, bool $isAdmin) {
    $user = User::factory()->make(['is_admin' => $isAdmin]);

    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(ProductPolicy::class);
    expect(Gate::forUser($user)->allows($ability, Product::class))->toBe($isAdmin);
})->with(['viewAny', 'view', 'create', 'update'])->with([true, false]);

it('denies permissions when an unsaved user has no administrator flag', function () {
    expect(Gate::forUser(User::factory()->make())->allows('create', Product::class))->toBeFalse();
});
