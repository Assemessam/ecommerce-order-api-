<?php

use App\DTOs\Promotion\CreatePromotionData;
use App\DTOs\Promotion\PromotionQuery;
use App\DTOs\Promotion\UpdatePromotionData;
use App\Enums\PromotionType;
use App\Exceptions\Domain\PromotionUsageLimitConflictException;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Promotion\PromotionAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('passes typed pagination and false active filtering through to the repository', function () {
    $actor = User::factory()->promotionManager()->create();
    $inactive = Promotion::factory()->inactive()->count(3)->create();
    Promotion::factory()->create();

    $promotions = app(PromotionAdministrationService::class)->listPromotions($actor, new PromotionQuery(page: 2, perPage: 1, isActive: false));

    expect($promotions->currentPage())->toBe(2);
    expect($promotions->perPage())->toBe(1);
    expect($promotions->total())->toBe(3);
    expect($promotions->items()[0]->id)->toBe($inactive->get(1)->id);
    expect($promotions->items()[0]->is_active)->toBeFalse();
});

it('persists typed commands while leaving database defaults and code normalization intact', function () {
    $actor = User::factory()->promotionManager()->create();
    $data = CreatePromotionData::fromArray(['code' => ' default ', 'type' => 'fixed', 'value' => 1, 'id' => 9000]);

    $promotion = app(PromotionAdministrationService::class)->createPromotion($actor, $data);

    expect($promotion->code)->toBe('DEFAULT');
    expect($promotion->type)->toBe(PromotionType::Fixed);
    expect($promotion->value)->toBe(1);
    expect($promotion->minimum_cart_amount_minor)->toBe(0);
    expect($promotion->is_active)->toBeTrue();
    expect($promotion->maximum_discount_minor)->toBeNull();
    expect($promotion->redemptions_count)->toBe(0);
    $this->assertDatabaseCount('promotions', 1);
    $this->assertDatabaseMissing('promotions', ['id' => 9000]);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('authorizes typed service calls before resource lookup despite the legacy administrator flag', function (string $method) {
    $actor = User::factory()->create(['is_admin' => true]);
    $service = app(PromotionAdministrationService::class);
    $arguments = match ($method) {
        'listPromotions' => [new PromotionQuery],
        'getPromotion' => ['not-an-id'],
        'updatePromotion' => ['not-an-id', UpdatePromotionData::fromArray(['is_active' => false])],
    };

    expect(fn () => $service->$method($actor, ...$arguments))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('promotions', 0);
})->with(['listPromotions', 'getPromotion', 'updatePromotion']);

it('keeps the percentage bound in the service for direct typed creation', function () {
    $actor = User::factory()->promotionManager()->create();
    $data = CreatePromotionData::fromArray(['code' => 'PERCENTAGE', 'type' => 'percentage', 'value' => 10001]);

    expect(fn () => app(PromotionAdministrationService::class)->createPromotion($actor, $data))
        ->toThrow(ValidationException::class, 'A percentage discount must not exceed 10000 basis points.');

    $this->assertDatabaseCount('promotions', 0);
});

it('keeps instant-based date ordering in the service for direct typed creation', function () {
    $actor = User::factory()->promotionManager()->create();
    $data = CreatePromotionData::fromArray([
        'code' => 'DATES', 'type' => 'fixed', 'value' => 1,
        'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-10-06T12:00:00.123456Z',
    ]);

    expect(fn () => app(PromotionAdministrationService::class)->createPromotion($actor, $data))
        ->toThrow(ValidationException::class, 'The expiration must be after the start date.');

    $this->assertDatabaseCount('promotions', 0);
});

it('checks typed partial changes against retained promotion values before persistence', function (array $attributes, array $input) {
    $actor = User::factory()->promotionManager()->create();
    $promotion = Promotion::factory()->create($attributes);
    $original = $promotion->refresh()->getAttributes();
    $data = UpdatePromotionData::fromArray($input);

    expect(fn () => app(PromotionAdministrationService::class)->updatePromotion($actor, (string) $promotion->id, $data))
        ->toThrow(ValidationException::class, 'A percentage discount must not exceed 10000 basis points.');

    expect($promotion->fresh()->getAttributes())->toBe($original);
})->with([
    'type retains excessive fixed value' => [['type' => 'fixed', 'value' => 12000], ['type' => 'percentage']],
    'value retains percentage type' => [['type' => 'percentage', 'value' => 2000], ['value' => 10001]],
]);

it('checks a typed partial date change against the retained boundary', function () {
    $actor = User::factory()->promotionManager()->create();
    $promotion = Promotion::factory()->create(['starts_at' => '2026-10-06T12:00:00Z', 'expires_at' => '2026-10-07T12:00:00Z']);
    $original = $promotion->refresh()->getAttributes();
    $data = UpdatePromotionData::fromArray(['starts_at' => '2026-10-08T15:00:00+03:00', 'is_active' => false]);

    expect(fn () => app(PromotionAdministrationService::class)->updatePromotion($actor, (string) $promotion->id, $data))
        ->toThrow(ValidationException::class, 'The expiration must be after the start date.');

    expect($promotion->fresh()->getAttributes())->toBe($original);
});

it('rejects typed usage-limit reductions below consumed usage without retaining other changes', function (string $field) {
    $actor = User::factory()->promotionManager()->create();
    $promotion = Promotion::factory()->limited(5, 5)->create();
    $customer = User::factory()->create();
    $records = PromotionRedemption::factory()->for($promotion)->for($customer)->count(2)->create();
    $records->each->refresh();
    $original = $promotion->refresh()->getAttributes();
    $data = UpdatePromotionData::fromArray([$field => 1, 'is_active' => false]);

    expect(fn () => app(PromotionAdministrationService::class)->updatePromotion($actor, (string) $promotion->id, $data))
        ->toThrow(PromotionUsageLimitConflictException::class);

    expect($promotion->fresh()->getAttributes())->toBe($original);
    expect(PromotionRedemption::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($records->map->getAttributes()->all());
})->with(['global_usage_limit', 'per_customer_usage_limit']);

it('clears nullable limits after consumed usage while retaining omitted promotion fields', function () {
    $actor = User::factory()->promotionManager()->create();
    $promotion = Promotion::factory()->limited(5, 5)->create([
        'maximum_discount_minor' => 500, 'minimum_cart_amount_minor' => 1000,
        'starts_at' => '2026-10-06T12:00:00.123456Z', 'expires_at' => '2026-11-06T12:00:00Z',
    ]);
    PromotionRedemption::factory()->for($promotion)->for(User::factory()->create())->count(2)->create();
    $data = UpdatePromotionData::fromArray([
        'maximum_discount_minor' => null, 'starts_at' => null, 'expires_at' => null,
        'global_usage_limit' => null, 'per_customer_usage_limit' => null,
        'minimum_cart_amount_minor' => 0, 'is_active' => false,
    ]);

    $updated = app(PromotionAdministrationService::class)->updatePromotion($actor, (string) $promotion->id, $data);

    expect($updated->code)->toBe($promotion->code);
    expect($updated->type)->toBe($promotion->type);
    expect($updated->value)->toBe($promotion->value);
    expect($updated->maximum_discount_minor)->toBeNull();
    expect($updated->starts_at)->toBeNull();
    expect($updated->expires_at)->toBeNull();
    expect($updated->global_usage_limit)->toBeNull();
    expect($updated->per_customer_usage_limit)->toBeNull();
    expect($updated->minimum_cart_amount_minor)->toBe(0);
    expect($updated->is_active)->toBeFalse();
    expect($updated->redemptions_count)->toBe(2);
    $this->assertDatabaseCount('promotion_redemptions', 2);
});
