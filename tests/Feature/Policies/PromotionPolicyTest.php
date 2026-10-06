<?php

use App\Models\Promotion;
use App\Models\User;
use App\Policies\PromotionPolicy;
use Illuminate\Support\Facades\Gate;

it('allows administrator catalogue permissions and denies default customers', function (string $ability, bool $isAdmin) {
    $user = User::factory()->make(['is_admin' => $isAdmin]);

    expect(Gate::getPolicyFor(Promotion::class))->toBeInstanceOf(PromotionPolicy::class);
    expect(Gate::forUser($user)->allows($ability, Promotion::class))->toBe($isAdmin);
})->with(['viewAny', 'view', 'create', 'update'])->with([true, false]);

it('denies permissions when an unsaved user has no administrator flag', function () {
    expect(Gate::forUser(User::factory()->make())->allows('create', Promotion::class))->toBeFalse();
});
