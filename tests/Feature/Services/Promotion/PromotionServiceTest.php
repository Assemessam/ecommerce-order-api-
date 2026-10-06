<?php

use App\Enums\PromotionIneligibilityReason as Reason;
use App\Exceptions\Domain\PromotionNotEligibleException;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Promotion\PromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
});

it('resolves all code variants to the same promotion', function (string $code) {
    $promotion = Promotion::factory()->create(['code' => 'SUMMER20']);

    expect(app(PromotionService::class)->resolveCode($code)->id)->toBe($promotion->id);
})->with(['summer20', ' SUMMER20 ', 'SUMMER20', "\tsummer20\n"]);

it('raises the unknown-code domain reason for missing codes', function () {
    try {
        app(PromotionService::class)->resolveCode('MISSING');
        test()->fail('Missing codes must fail.');
    } catch (PromotionNotEligibleException $exception) {
        expect($exception->reason)->toBe(Reason::Unknown);
    }
});

it('checks dates, active status, minimum and cart state deterministically', function (array $attributes, int $subtotal, bool $hasItems, bool $purchasable, ?Reason $reason) {
    $promotion = Promotion::factory()->create($attributes);
    $user = User::factory()->create();

    $result = app(PromotionService::class)->eligibility($promotion->fresh(), $user->id, $subtotal, $hasItems, $purchasable);

    expect($result->reason)->toBe($reason);
    expect($result->isEligible())->toBe($reason === null);
})->with([
    'valid unlimited' => [[], 10000, true, true, null],
    'inactive' => [['is_active' => false], 10000, true, true, Reason::Inactive],
    'expiry instant excluded' => [['expires_at' => '2026-10-06T12:00:00Z'], 10000, true, true, Reason::Expired],
    'expired' => [['expires_at' => '2026-10-05T12:00:00Z'], 10000, true, true, Reason::Expired],
    'future' => [['starts_at' => '2026-10-07T12:00:00Z'], 10000, true, true, Reason::NotStarted],
    'start instant included' => [['starts_at' => '2026-10-06T12:00:00Z'], 10000, true, true, null],
    'timezone equivalent start' => [['starts_at' => '2026-10-06T15:00:00+03:00'], 10000, true, true, null],
    'one microsecond before expiry' => [['expires_at' => '2026-10-06T12:00:00.000001Z'], 10000, true, true, null],
    'exact minimum' => [['minimum_cart_amount_minor' => 10000], 10000, true, true, null],
    'below minimum' => [['minimum_cart_amount_minor' => 10000], 9999, true, true, Reason::MinimumNotMet],
    'empty' => [[], 0, false, true, Reason::EmptyCart],
    'invalid cart' => [[], 10000, true, false, Reason::InvalidCartState],
]);

it('enforces ledger counts independently for each promotion and customer', function (array $limits, int $ownUses, int $otherUses, ?Reason $reason) {
    $promotion = Promotion::factory()->create($limits);
    $user = User::factory()->create();
    PromotionRedemption::factory()->count($ownUses)->for($promotion)->for($user)->create();
    PromotionRedemption::factory()->count($otherUses)->for($promotion)->create();
    PromotionRedemption::factory()->for($user)->create();

    $result = app(PromotionService::class)->eligibility($promotion, $user->id, 10000, true, true);

    expect($result->reason)->toBe($reason);
    expect($result->isEligible())->toBe($reason === null);
})->with([
    'global boundary' => [['global_usage_limit' => 2], 0, 2, Reason::GlobalLimitReached],
    'customer boundary' => [['per_customer_usage_limit' => 1], 1, 0, Reason::CustomerLimitReached],
    'other customers do not exhaust customer limit' => [['per_customer_usage_limit' => 1], 0, 2, null],
    'below both limits' => [['global_usage_limit' => 3, 'per_customer_usage_limit' => 2], 1, 1, null],
    'unlimited despite uses' => [[], 3, 3, null],
]);
