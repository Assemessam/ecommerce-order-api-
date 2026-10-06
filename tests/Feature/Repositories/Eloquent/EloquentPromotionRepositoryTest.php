<?php

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Models\Cart;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Repositories\Eloquent\EloquentPromotionRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('binds the promotion contract and resolves exact canonical codes without filtering inactive promotions', function () {
    $promotion = Promotion::factory()->inactive()->create(['code' => 'INACTIVE']);
    $repository = app(PromotionRepositoryInterface::class);

    expect($repository)->toBeInstanceOf(EloquentPromotionRepository::class);
    expect($repository->findByCode('INACTIVE')->id)->toBe($promotion->id);
    expect($repository->findByCode('UNKNOWN'))->toBeNull();
    expect($repository->findByCode("' OR 1=1 --"))->toBeNull();
});

it('counts global and customer uses in one query without exposing records', function () {
    $promotion = Promotion::factory()->create();
    $user = User::factory()->create();
    PromotionRedemption::factory()->count(2)->for($promotion)->for($user)->create();
    PromotionRedemption::factory()->for($promotion)->create();
    PromotionRedemption::factory()->for($user)->create();
    DB::enableQueryLog();

    $counts = app(PromotionRepositoryInterface::class)->redemptionCounts($promotion, $user->id);

    expect($counts)->toBe(['global' => 3, 'customer' => 2]);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});

it('persists and removes selected promotions only on the scoped cart and participates in rollback', function () {
    $cart = Cart::factory()->create();
    $otherCart = Cart::factory()->create();
    $promotion = Promotion::factory()->create();
    $repository = app(CartRepositoryInterface::class);

    DB::transaction(function () use ($repository, $cart, $promotion): void {
        $owned = $repository->lockForUser($cart->user);
        $repository->setPromotion($owned, $promotion);
    });

    expect($repository->findForUser($cart->user)->promotion->id)->toBe($promotion->id);
    expect($otherCart->fresh()->promotion_id)->toBeNull();
    expect(fn () => DB::transaction(function () use ($repository, $cart): void {
        $repository->setPromotion($repository->lockForUser($cart->user), null);
        throw new RuntimeException('Rollback selection');
    }))->toThrow(RuntimeException::class, 'Rollback selection');
    expect($cart->fresh()->promotion_id)->toBe($promotion->id);

    DB::transaction(fn () => $repository->setPromotion($repository->lockForUser($cart->user), null));
    expect($cart->fresh()->promotion_id)->toBeNull();
});
