<?php

use App\Models\PromotionRedemption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('rejects duplicate redemption keys even across customers and promotions', function () {
    $redemption = PromotionRedemption::factory()->create();

    expect(fn () => DB::transaction(fn () => PromotionRedemption::factory()->create(['redemption_key' => $redemption->redemption_key])))
        ->toThrow(QueryException::class, 'promotion_redemptions_redemption_key_unique');
    $this->assertDatabaseCount('promotion_redemptions', 1);
});

it('protects redemption foreign keys and nonnegative discount snapshots', function (string $field, int $value, string $constraint) {
    $redemption = PromotionRedemption::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('promotion_redemptions')->where('id', $redemption->id)->update([$field => $value])))
        ->toThrow(QueryException::class, $constraint);
    $this->assertModelExists($redemption);
})->with([
    ['promotion_id', PHP_INT_MAX, 'promotion_redemptions_promotion_id_foreign'],
    ['user_id', PHP_INT_MAX, 'promotion_redemptions_user_id_foreign'],
    ['discount_minor', -1, 'promotion_redemptions_discount_non_negative'],
]);

it('preserves audit records when referenced customers or promotions are deleted', function (string $relation) {
    $redemption = PromotionRedemption::factory()->create(['discount_minor' => 0]);

    expect(fn () => DB::transaction(fn () => $redemption->$relation->delete()))
        ->toThrow(QueryException::class, 'promotion_redemptions_'.$relation.'_id_foreign');
    expect($redemption->fresh()->discount_minor)->toBe(0);
    $this->assertModelExists($redemption);
})->with(['user', 'promotion']);

it('preserves legacy ledger relationships without creating an order placeholder', function () {
    $redemption = PromotionRedemption::factory()->create();

    expect($redemption->promotion->redemptions->sole()->id)->toBe($redemption->id);
    expect($redemption->user->promotionRedemptions->sole()->id)->toBe($redemption->id);
    expect($redemption->order_id)->toBeNull();
    expect($redemption->order)->toBeNull();
    $this->assertDatabaseCount('orders', 0);
});
