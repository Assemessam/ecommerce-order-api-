<?php

use App\Enums\PromotionType;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
});

describe('creation', function () {
    it('creates normalized percentage or fixed promotions without consuming usage', function (string $type, int $value) {
        $response = $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/promotions', [
                'code' => ' save-20 ', 'type' => $type, 'value' => $value,
                'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500,
                'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-11-06T12:00:00Z',
                'global_usage_limit' => 10, 'per_customer_usage_limit' => 2, 'is_active' => false,
                'id' => 9000, 'redemptions_count' => 9, 'user_id' => 9000,
            ])->assertCreated()->assertJsonPath('data.code', 'SAVE-20')
            ->assertJsonPath('data.type', $type)->assertJsonPath('data.value', $value)
            ->assertJsonPath('data.starts_at', '2026-10-06T12:00:00.123456Z')
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.redemptions_count', 0);

        $this->assertDatabaseHas('promotions', ['id' => $response->json('data.id'), 'code' => 'SAVE-20', 'type' => $type, 'value' => $value, 'global_usage_limit' => 10]);
        $this->assertDatabaseMissing('promotions', ['id' => 9000]);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    })->with(['percentage' => ['percentage', 2000], 'fixed' => ['fixed', PHP_INT_MAX]]);

    it('uses defaults for optional promotion properties', function () {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/promotions', ['code' => 'DEFAULT', 'type' => 'fixed', 'value' => 1])
            ->assertCreated()->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.minimum_cart_amount_minor', 0)->assertJsonPath('data.starts_at', null)
            ->assertJsonPath('data.global_usage_limit', null)->assertJsonPath('data.per_customer_usage_limit', null);
    });

    it('returns 422 for invalid promotion fields without creating a promotion', function (string $field, mixed $value) {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/promotions', array_replace(['code' => 'SAVE', 'type' => 'percentage', 'value' => 2000], [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        $this->assertDatabaseCount('promotions', 0);
    })->with([
        'blank code' => ['code', ' '], 'bad code' => ['code', 'BAD CODE'],
        'long code' => ['code', str_repeat('A', 65)], 'null byte code' => ['code', "BAD\0CODE"],
        'SQL code' => ['code', "' OR 1=1 --"], 'unknown type' => ['type', 'bogus'],
        'zero discount' => ['value', 0], 'negative discount' => ['value', -1],
        'percentage overflow' => ['value', 10001], 'float discount' => ['value', 2.5],
        'string discount' => ['value', '2000'], 'bigint overflow' => ['value', 9223372036854775808.0],
        'negative minimum' => ['minimum_cart_amount_minor', -1], 'null minimum' => ['minimum_cart_amount_minor', null],
        'zero cap' => ['maximum_discount_minor', 0], 'float cap' => ['maximum_discount_minor', 1.5],
        'zero global limit' => ['global_usage_limit', 0], 'string global limit' => ['global_usage_limit', '5'],
        'negative customer limit' => ['per_customer_usage_limit', -1],
        'overflow customer limit' => ['per_customer_usage_limit', 9223372036854775808.0],
        'invalid start' => ['starts_at', 'tomorrow'], 'missing date offset' => ['starts_at', '2026-10-06T12:00:00'],
        'invalid expiry' => ['expires_at', 'invalid'], 'invalid calendar date' => ['expires_at', '2026-02-30T12:00:00Z'],
        'string active flag' => ['is_active', 'true'], 'integer active flag' => ['is_active', 1],
        'null active flag' => ['is_active', null],
    ]);

    it('returns 422 for unordered validity dates including equivalent instants in different offsets', function (string $start, string $end) {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/promotions', ['code' => 'DATES', 'type' => 'fixed', 'value' => 1, 'starts_at' => $start, 'expires_at' => $end])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.expires_at.0', 'The expiration must be after the start date.');

        $this->assertDatabaseCount('promotions', 0);
    })->with([
        'reversed' => ['2026-11-06T12:00:00Z', '2026-10-06T12:00:00Z'],
        'equal' => ['2026-10-06T15:00:00+03:00', '2026-10-06T12:00:00Z'],
    ]);

    it('returns 422 for duplicate normalized codes', function () {
        Promotion::factory()->create(['code' => 'EXISTING']);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/promotions', ['code' => ' existing ', 'type' => 'fixed', 'value' => 1])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.code.0', 'The code has already been taken.');

        $this->assertDatabaseCount('promotions', 1);
    });
});

describe('listing and detail', function () {
    it('preserves an inactive query filter in results and pagination links', function (string $filter) {
        Promotion::factory()->create();
        Promotion::factory()->inactive()->count(2)->create();

        $response = $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->getJson('/api/admin/promotions?is_active='.$filter.'&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 1);

        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $parameters);

        expect($parameters)->toMatchArray(['is_active' => '0', 'per_page' => '1', 'page' => '2']);
    })->with(['false word' => 'false', 'zero string' => '0']);

    it('lists inactive promotions and ledger counts with stable pagination and optional active filtering', function () {
        $active = Promotion::factory()->create();
        $inactive = Promotion::factory()->inactive()->create();
        PromotionRedemption::factory()->for($inactive)->count(2)->create();
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken);

        $this->getJson('/api/admin/promotions?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $inactive->id)->assertJsonPath('data.0.redemptions_count', 2);
        $this->getJson('/api/admin/promotions?is_active=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/admin/promotions/'.$inactive->id)->assertOk()->assertJsonPath('data.redemptions_count', 2)
            ->assertJsonMissingPath('data.redemptions')->assertJsonMissingPath('data.user_id');
        $this->getJson('/api/admin/promotions?per_page=101')->assertUnprocessable();
        $this->getJson('/api/admin/promotions?is_active=arbitrary')->assertUnprocessable();
    });

    it('returns 404 for missing zero and overflowing identifiers', function (string $id) {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->getJson('/api/admin/promotions/'.$id)->assertNotFound();
    })->with(['999', '0', '9223372036854775808']);
});

describe('updates', function () {
    it('preserves omitted promotion fields while accepting zero minimum and false active status', function () {
        $promotion = Promotion::factory()->future()->limited(5, 2)->create([
            'minimum_cart_amount_minor' => 1000, 'maximum_discount_minor' => 500,
        ]);
        $original = $promotion->refresh()->getAttributes();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, ['minimum_cart_amount_minor' => 0, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.minimum_cart_amount_minor', 0)->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.maximum_discount_minor', 500)->assertJsonPath('data.global_usage_limit', 5)
            ->assertJsonPath('data.per_customer_usage_limit', 2);

        expect($promotion->fresh()->getAttributes())->toBe(array_replace($original, [
            'minimum_cart_amount_minor' => 0, 'is_active' => false,
        ]));
    });

    it('accepts an empty promotion patch while retaining saved attributes and usage', function () {
        $promotion = Promotion::factory()->future()->limited()->create();
        PromotionRedemption::factory()->for($promotion)->create();
        $original = $promotion->refresh()->getAttributes();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, [])
            ->assertOk()->assertJsonPath('data.redemptions_count', 1);

        expect($promotion->fresh()->getAttributes())->toBe($original);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    });

    it('changes future promotion inputs while preserving the order and its redemption', function () {
        $promotion = Promotion::factory()->create(['code' => 'ORIGINAL', 'value' => 2000]);
        $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
        $item->cart->promotion()->associate($promotion)->save();
        $token = $item->cart->user->createToken('customer')->plainTextToken;
        $this->withToken($token)->postJson('/api/checkout')->assertCreated()->assertJsonPath('data.discount.amount_minor', 2000);
        $order = Order::query()->sole();
        $original = $order->getAttributes();
        $redemption = PromotionRedemption::query()->sole()->getAttributes();
        $this->app['auth']->forgetGuards();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, [
                'code' => ' updated ', 'type' => 'fixed', 'value' => 500,
                'minimum_cart_amount_minor' => 5000, 'maximum_discount_minor' => 300,
                'starts_at' => '2026-10-06T10:00:00Z', 'expires_at' => '2026-10-07T12:00:00Z',
                'global_usage_limit' => 3, 'per_customer_usage_limit' => 2, 'is_active' => false,
                'redemptions_count' => 0, 'redeemed_at' => '2000-01-01',
            ])->assertOk()->assertJsonPath('data.code', 'UPDATED')->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', 500)->assertJsonPath('data.redemptions_count', 1)
            ->assertJsonPath('data.is_active', false);

        expect($order->fresh()->getAttributes())->toBe($original);
        expect(PromotionRedemption::query()->sole()->getAttributes())->toBe($redemption);
        $this->patchJson('/api/admin/promotions/'.$promotion->id, ['is_active' => true])->assertOk();
        CartItem::factory()->for($item->cart)->for($item->product)->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/cart/promotion', ['code' => 'updated'])->assertOk()->assertJsonPath('data.estimated_discount.amount_minor', 300);
        $this->postJson('/api/checkout')->assertCreated()->assertJsonPath('data.discount.amount_minor', 300);
        $this->assertDatabaseCount('promotion_redemptions', 2);
    });

    it('returns 422 for a percentage change incompatible with a retained fixed value', function () {
        $promotion = Promotion::factory()->fixed(12000)->create();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, ['type' => 'percentage'])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.value.0', 'A percentage discount must not exceed 10000 basis points.');

        expect($promotion->fresh()->type)->toBe(PromotionType::Fixed);
        expect($promotion->fresh()->value)->toBe(12000);
    });

    it('returns 422 when a partial date change conflicts with the retained boundary', function (array $data) {
        $promotion = Promotion::factory()->create(['starts_at' => '2026-10-06T12:00:00Z', 'expires_at' => '2026-10-07T12:00:00Z']);
        $original = $promotion->refresh()->getAttributes();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, $data)
            ->assertUnprocessable()->assertJsonPath('error.details.fields.expires_at.0', 'The expiration must be after the start date.');

        expect($promotion->fresh()->getAttributes())->toBe($original);
    })->with([
        'start only' => [['starts_at' => '2026-10-08T12:00:00Z']],
        'end only' => [['expires_at' => '2026-10-05T12:00:00Z']],
    ]);

    it('clears optional caps dates and limits using explicit nulls', function () {
        $promotion = Promotion::factory()->future()->limited()->create(['maximum_discount_minor' => 500]);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, [
                'starts_at' => null, 'expires_at' => null, 'maximum_discount_minor' => null,
                'global_usage_limit' => null, 'per_customer_usage_limit' => null,
            ])->assertOk()->assertJsonPath('data.starts_at', null)->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.maximum_discount_minor', null)->assertJsonPath('data.global_usage_limit', null)
            ->assertJsonPath('data.per_customer_usage_limit', null);

        expect($promotion->fresh()->global_usage_limit)->toBeNull();
    });

    it('returns 409 for a limit below existing usage and preserves every field and ledger record', function (string $field) {
        $promotion = Promotion::factory()->limited(5, 5)->create();
        $customer = User::factory()->create();
        $records = PromotionRedemption::factory()->for($promotion)->for($customer)->count(2)->create();
        $records->each->refresh();
        $original = $promotion->refresh()->getAttributes();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, [$field => 1, 'is_active' => false])
            ->assertConflict()->assertJsonPath('error.code', 'PROMOTION_USAGE_LIMIT_CONFLICT');

        expect($promotion->fresh()->getAttributes())->toBe($original);
        expect(PromotionRedemption::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($records->map->getAttributes()->all());
    })->with(['global_usage_limit', 'per_customer_usage_limit']);

    it('allows a limit equal to consumed usage and immediately blocks further eligibility', function () {
        $promotion = Promotion::factory()->limited(5, 5)->create();
        $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
        PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->count(2)->create();
        $item->cart->promotion()->associate($promotion)->save();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, ['global_usage_limit' => 2, 'per_customer_usage_limit' => 2])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($item->cart->user->createToken('customer')->plainTextToken)->postJson('/api/checkout')
            ->assertConflict()->assertJsonPath('error.code', 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED');

        $this->assertDatabaseCount('promotion_redemptions', 2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertModelExists($item);
    });

    it('checks the largest individual customer usage rather than treating global usage as customer usage', function () {
        $promotion = Promotion::factory()->limited(10, 5)->create();
        PromotionRedemption::factory()->for($promotion)->count(3)->create();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, ['per_customer_usage_limit' => 1])->assertOk();

        expect($promotion->fresh()->per_customer_usage_limit)->toBe(1);
        $this->assertDatabaseCount('promotion_redemptions', 3);
    });

    it('returns 422 for duplicate code updates but permits the same normalized code', function () {
        Promotion::factory()->create(['code' => 'OTHER']);
        $promotion = Promotion::factory()->create(['code' => 'ORIGINAL']);
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken);

        $this->patchJson('/api/admin/promotions/'.$promotion->id, ['code' => ' other ', 'is_active' => false])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.code.0', 'The code has already been taken.');
        expect($promotion->fresh()->is_active)->toBeTrue();
        expect($promotion->fresh()->code)->toBe('ORIGINAL');
        $this->patchJson('/api/admin/promotions/'.$promotion->id, ['code' => ' original '])->assertOk();
    });

    it('deactivates selected promotions without erasing selection and checkout rejects future redemption', function () {
        $promotion = Promotion::factory()->create();
        $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
        $item->cart->promotion()->associate($promotion)->save();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/promotions/'.$promotion->id, ['is_active' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($item->cart->user->createToken('customer')->plainTextToken)->postJson('/api/checkout')
            ->assertUnprocessable()->assertJsonPath('error.code', 'PROMOTION_INACTIVE');

        expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    });

    it('provides no promotion hard-delete endpoint', function () {
        $promotion = Promotion::factory()->create();
        PromotionRedemption::factory()->for($promotion)->create();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->deleteJson('/api/admin/promotions/'.$promotion->id)->assertMethodNotAllowed();

        $this->assertModelExists($promotion);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    });
});

it('returns 422 for missing required promotion creation fields', function () {
    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->postJson('/api/admin/promotions', [])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['code', 'type', 'value']]]]);

    $this->assertDatabaseCount('promotions', 0);
});

it('returns 422 for an excessive value patch against the retained percentage type', function () {
    $promotion = Promotion::factory()->create(['value' => 2000]);

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/promotions/'.$promotion->id, ['value' => 10001])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.value.0', 'A percentage discount must not exceed 10000 basis points.');

    expect($promotion->fresh()->value)->toBe(2000);
});

it('rolls back promotion edits on unexpected persistence failure and preserves the ledger', function () {
    $promotion = Promotion::factory()->create();
    PromotionRedemption::factory()->for($promotion)->create();
    $original = $promotion->refresh()->getAttributes();
    Event::listen('eloquent.updated: '.Promotion::class, function (): void {
        throw new RuntimeException('Private database detail');
    });

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/promotions/'.$promotion->id, ['is_active' => false])
        ->assertInternalServerError()->assertJsonPath('error.code', 'INTERNAL_ERROR');

    expect($promotion->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('promotion_redemptions', 1);
});
