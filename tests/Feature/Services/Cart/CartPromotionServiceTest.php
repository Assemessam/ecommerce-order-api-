<?php

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Cart\CartPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

it('rolls back replacement if a failure occurs after the selected promotion was persisted', function () {
    $item = CartItem::factory()->create();
    $original = Promotion::factory()->create();
    $replacement = Promotion::factory()->create();
    $item->cart->promotion()->associate($original)->save();
    Event::listen('eloquent.updated: '.Cart::class, function (Cart $cart): void {
        throw new RuntimeException('Injected failure after selection');
    });

    expect(fn () => app(CartPromotionService::class)->apply($item->cart->user, $replacement->code))
        ->toThrow(RuntimeException::class, 'Injected failure after selection');
    expect($item->cart->fresh()->promotion_id)->toBe($original->id);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('maps persistence constraint failures to safe 409 responses after rolling back', function () {
    config(['app.debug' => true]);
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    Event::listen('eloquent.updated: '.Cart::class, function (Cart $cart): void {
        DB::table('carts')->where('id', $cart->id)->update(['promotion_id' => PHP_INT_MAX]);
    });

    $response = $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)
        ->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertConflict()->assertJsonPath('error.code', 'CART_CONFLICT');
    expect($response->getContent())->not->toContain('SQLSTATE', 'trace');
    expect($item->cart->fresh()->promotion_id)->toBeNull();
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('returns 404 when a repository accidentally supplies another customer cart for apply or removal', function (string $operation) {
    $user = User::factory()->create();
    $otherCart = Cart::factory()->create();
    $repository = Mockery::mock(CartRepositoryInterface::class);
    $repository->shouldReceive('lockForUser')->once()->andReturn($otherCart);
    $repository->shouldNotReceive('setPromotion');
    $this->app->instance(CartRepositoryInterface::class, $repository);
    $this->withToken($user->createToken('promotion-test')->plainTextToken);

    if ($operation === 'apply') {
        $this->postJson('/api/cart/promotion', ['code' => 'ANY'])->assertNotFound();
    } else {
        $this->deleteJson('/api/cart/promotion')->assertNotFound();
    }

    expect($otherCart->fresh()->promotion_id)->toBeNull();
})->with(['apply', 'remove']);

it('rolls back removal when persistence completes but the transaction subsequently fails', function () {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    Event::listen('eloquent.updated: '.Cart::class, function (Cart $cart): void {
        throw new RuntimeException('Injected removal failure');
    });

    expect(fn () => app(CartPromotionService::class)->remove($item->cart->user))
        ->toThrow(RuntimeException::class, 'Injected removal failure');
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});
