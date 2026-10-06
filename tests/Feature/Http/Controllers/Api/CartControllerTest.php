<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('returns 401 on every cart endpoint without a bearer token', function (string $method, string $path) {
    $this->json($method, $path, ['product_id' => 1, 'quantity' => 1])
        ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
})->with([
    ['GET', '/api/cart'], ['POST', '/api/cart/items'],
    ['PATCH', '/api/cart/items/1'], ['DELETE', '/api/cart/items/1'],
]);

it('returns 401 for invalid and revoked bearer tokens', function (bool $revoked) {
    $user = User::factory()->create();
    $token = $user->createToken('cart-test');
    $bearer = $revoked ? $token->plainTextToken : 'invalid';
    $token->accessToken->delete();

    $this->withToken($bearer)->getJson('/api/cart')->assertUnauthorized();
})->with([false, true]);

it('returns an empty estimate without writing a cart or exposing another customer', function () {
    $otherItem = CartItem::factory()->create();
    $user = User::factory()->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->getJson('/api/cart')
        ->assertOk()->assertExactJson(['data' => [
            'id' => null, 'items' => [], 'subtotal' => ['amount_minor' => 0, 'currency' => 'USD'],
            'promotion' => null, 'promotion_eligibility' => null,
            'estimated_discount' => ['amount_minor' => 0, 'currency' => 'USD'],
            'estimated_total' => ['amount_minor' => 0, 'currency' => 'USD'],
        ]]);

    $this->assertDatabaseCount('carts', 1);
    $this->assertModelExists($otherItem);
    $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
});

it('adds a server-priced product ignoring forged ownership and money fields', function () {
    $user = User::factory()->create();
    $otherCart = Cart::factory()->create();
    $product = Product::factory()->create(['price_minor' => 1899, 'stock_quantity' => 5]);

    $response = $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', [
        'product_id' => $product->id, 'quantity' => 2,
        'user_id' => $otherCart->user_id, 'cart_id' => $otherCart->id,
        'price' => 1, 'price_minor' => 1, 'subtotal' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.items.0.product.id', $product->id)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price', ['amount_minor' => 1899, 'currency' => 'USD'])
        ->assertJsonPath('data.items.0.line_subtotal.amount_minor', 3798)
        ->assertJsonPath('data.subtotal.amount_minor', 3798)
        ->assertJsonPath('data.items.0.availability', ['is_available' => true, 'reason' => null, 'available_quantity' => 5]);

    $this->assertDatabaseHas('carts', ['id' => $response->json('data.id'), 'user_id' => $user->id]);
    $this->assertDatabaseHas('cart_items', ['cart_id' => $response->json('data.id'), 'product_id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseMissing('cart_items', ['cart_id' => $otherCart->id]);
    expect($product->fresh()->stock_quantity)->toBe(5);
    expect(array_keys($response->json('data')))->toBe(['id', 'items', 'subtotal', 'promotion', 'promotion_eligibility', 'estimated_discount', 'estimated_total']);
    expect(array_keys($response->json('data.items.0')))->toBe(['id', 'product', 'quantity', 'unit_price', 'line_subtotal', 'availability']);
    expect(array_keys($response->json('data.items.0.product')))->toBe(['id', 'name', 'sku', 'description', 'price', 'stock_quantity', 'status', 'created_at', 'updated_at']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $response->assertHeader('X-Request-ID');
});

it('merges repeated adds and rejects the accumulated quantity above stock with 409', function () {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $item->product->update(['stock_quantity' => 5]);
    $token = $item->cart->user->createToken('cart-test')->plainTextToken;
    $this->withToken($token)->postJson('/api/cart/items', ['product_id' => $item->product_id, 'quantity' => 3])
        ->assertCreated()->assertJsonPath('data.items.0.id', $item->id)->assertJsonPath('data.items.0.quantity', 5);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/cart/items', ['product_id' => $item->product_id, 'quantity' => 1])
        ->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('error.details', ['product_id' => $item->product_id, 'available' => 5]);

    $this->assertDatabaseCount('cart_items', 1);
    expect($item->fresh()->quantity)->toBe(5);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
});

it('returns 404 for a nonexistent product and rolls back first cart creation', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => PHP_INT_MAX, 'quantity' => 1])
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
});

it('returns 409 for ineligible additions without persisting a cart', function (string $status, int $stock, int $quantity, string $code) {
    $user = User::factory()->create();
    $product = Product::factory()->create(['status' => $status, 'stock_quantity' => $stock]);

    $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => $quantity])
        ->assertConflict()->assertJsonPath('error.code', $code);

    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
    expect($product->fresh()->stock_quantity)->toBe($stock);
})->with([
    'inactive' => ['inactive', 10, 1, 'PRODUCT_INACTIVE'],
    'empty stock' => ['active', 0, 1, 'INSUFFICIENT_STOCK'],
    'above stock' => ['active', 2, 3, 'INSUFFICIENT_STOCK'],
]);

it('returns 422 for invalid item quantities before persistence', function (string $method, mixed $quantity, string $message) {
    $item = CartItem::factory()->create();
    $path = $method === 'POST' ? '/api/cart/items' : '/api/cart/items/'.$item->id;

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->json($method, $path, [
        'product_id' => $item->product_id, 'quantity' => $quantity,
    ], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.fields.quantity', [$message]);

    expect($item->fresh()->quantity)->toBe(1);
    $this->assertDatabaseCount('cart_items', 1);
})->with(['POST', 'PATCH'])->with([
    'zero' => [0, 'The quantity field must be at least 1.'],
    'negative' => [-1, 'The quantity field must be at least 1.'],
    'fraction' => [1.5, 'The quantity field must be an integer.'],
    'whole float' => [1.0, 'The quantity field must be an integer.'],
    'boolean' => [true, 'The quantity field must be an integer.'],
    'array' => [[1], 'The quantity field must be an integer.'],
    'string' => ['2', 'The quantity field must be an integer.'],
    'overflow' => ['9223372036854775808', 'The quantity field must be an integer.'],
    'null' => [null, 'The quantity field is required.'],
]);

it('returns 422 for missing quantity on add and update', function (string $method, string $path) {
    $user = User::factory()->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->json($method, $path, ['product_id' => 1])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.quantity', ['The quantity field is required.']);

    $this->assertDatabaseCount('carts', 0);
})->with([['POST', '/api/cart/items'], ['PATCH', '/api/cart/items/1']]);

it('returns 422 for malformed product identifiers', function (mixed $id, string $message) {
    $user = User::factory()->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.product_id', [$message]);

    $this->assertDatabaseCount('carts', 0);
})->with([
    [null, 'The product id field is required.'], [0, 'The product id field must be at least 1.'],
    [-1, 'The product id field must be at least 1.'], ['bad', 'The product id field must be an integer.'],
    ['9223372036854775808', 'The product id field must be an integer.'],
    [true, 'The product id field must be an integer.'], [[1], 'The product id field must be an integer.'],
]);

it('replaces quantity using the cart item ID and ignores attempts to reassign it', function () {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $otherItem = CartItem::factory()->create();
    $item->product->update(['price_minor' => 1250, 'stock_quantity' => 5]);

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->patchJson('/api/cart/items/'.$item->id, [
        'quantity' => 3, 'product_id' => $otherItem->product_id,
        'cart_id' => $otherItem->cart_id, 'user_id' => $otherItem->cart->user_id, 'price_minor' => 0,
    ])->assertOk()->assertJsonPath('data.items.0.id', $item->id)
        ->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.subtotal.amount_minor', 3750);

    $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'cart_id' => $item->cart_id, 'product_id' => $item->product_id, 'quantity' => 3]);
    expect($otherItem->fresh()->quantity)->toBe(1);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
});

it('returns 409 when update revalidation fails and preserves the line', function (string $status, int $stock, string $code) {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $item->product->update(['status' => $status, 'stock_quantity' => $stock]);

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->patchJson('/api/cart/items/'.$item->id, ['quantity' => 3])
        ->assertConflict()->assertJsonPath('error.code', $code);

    expect($item->fresh()->quantity)->toBe(2);
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
})->with([
    ['active', 2, 'INSUFFICIENT_STOCK'], ['active', 0, 'INSUFFICIENT_STOCK'], ['inactive', 10, 'PRODUCT_INACTIVE'],
]);

it('returns 404 for another customer item without altering it or creating a cart', function (string $method) {
    $item = CartItem::factory()->create();
    $user = User::factory()->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->json($method, '/api/cart/items/'.$item->id, ['quantity' => 2])
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
        ->assertJsonMissingPath('error.details');

    expect($item->fresh()->quantity)->toBe(1);
    $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
})->with(['PATCH', 'DELETE']);

it('returns 404 for another customer item when the caller already owns a cart', function (string $method) {
    $ownItem = CartItem::factory()->create();
    $otherItem = CartItem::factory()->create();

    $this->withToken($ownItem->cart->user->createToken('cart-test')->plainTextToken)->json($method, '/api/cart/items/'.$otherItem->id, ['quantity' => 2])
        ->assertNotFound();

    expect($otherItem->fresh()->quantity)->toBe(1);
    expect($ownItem->fresh()->quantity)->toBe(1);
})->with(['PATCH', 'DELETE']);

it('returns 404 for missing or malformed item IDs', function (string $method, string $id) {
    $user = User::factory()->create();
    Cart::factory()->for($user)->create();

    $this->withToken($user->createToken('cart-test')->plainTextToken)->json($method, '/api/cart/items/'.$id, ['quantity' => 1])
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

    $this->assertDatabaseCount('cart_items', 0);
})->with(['PATCH', 'DELETE'])->with(['1', '0', '-1', 'abc', '9223372036854775808']);

it('removes even an inactive out-of-stock item without restoring stock and retains the empty cart', function () {
    $item = CartItem::factory()->create();
    $item->product->update(['status' => ProductStatus::Inactive, 'stock_quantity' => 0]);
    $token = $item->cart->user->createToken('cart-test')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/cart/items/'.$item->id)->assertNoContent();

    $this->assertModelMissing($item);
    $this->assertModelExists($item->cart);
    expect($item->product->fresh()->stock_quantity)->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/cart')->assertOk()->assertJsonPath('data.id', $item->cart_id)
        ->assertJsonPath('data.items', [])->assertJsonPath('data.subtotal.amount_minor', 0);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->deleteJson('/api/cart/items/'.$item->id)->assertNotFound();
});

it('calculates multiple line totals from current prices while keeping unavailable items visible', function () {
    $cart = Cart::factory()->create();
    $first = CartItem::factory()->for($cart)->for(Product::factory()->create(['price_minor' => 1250, 'stock_quantity' => 5]))->create(['quantity' => 2]);
    CartItem::factory()->for($cart)->for(Product::factory()->create(['price_minor' => 399, 'stock_quantity' => 3]))->create(['quantity' => 3]);
    $otherItem = CartItem::factory()->create();
    $token = $cart->user->createToken('cart-test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/cart')->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.line_subtotal.amount_minor', 2500)
        ->assertJsonPath('data.items.1.line_subtotal.amount_minor', 1197)
        ->assertJsonPath('data.subtotal.amount_minor', 3697);
    expect(array_column($response->json('data.items'), 'id'))->not->toContain($otherItem->id);
    $first->product->update(['price_minor' => 1500, 'status' => ProductStatus::Inactive]);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/cart')->assertOk()
        ->assertJsonPath('data.items.0.unit_price.amount_minor', 1500)
        ->assertJsonPath('data.items.0.line_subtotal.amount_minor', 3000)
        ->assertJsonPath('data.subtotal.amount_minor', 4197)
        ->assertJsonPath('data.items.0.product.status', 'inactive')
        ->assertJsonPath('data.items.0.availability.reason', 'inactive')
        ->assertJsonPath('data.items.0.availability.is_available', false);

    expect($first->fresh()->quantity)->toBe(2);
});

it('reports changed stock without silently removing or reducing the line', function (int $stock, string $reason) {
    $item = CartItem::factory()->create(['quantity' => 4]);
    $item->product->update(['stock_quantity' => $stock]);

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->getJson('/api/cart')->assertOk()
        ->assertJsonPath('data.items.0.quantity', 4)
        ->assertJsonPath('data.items.0.availability', ['is_available' => false, 'reason' => $reason, 'available_quantity' => $stock]);

    expect($item->fresh()->quantity)->toBe(4);
})->with([[0, 'out_of_stock'], [2, 'insufficient_stock']]);

it('uses the configured currency and exact large integer totals', function () {
    config(['catalogue.currency' => 'EUR']);
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 9007199254740993, 'stock_quantity' => 2]))->create(['quantity' => 2]);

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->getJson('/api/cart')
        ->assertOk()->assertJsonPath('data.subtotal', ['amount_minor' => 18014398509481986, 'currency' => 'EUR']);
});

it('loads cart items and products in three queries regardless of the line count', function () {
    $cart = Cart::factory()->create();
    CartItem::factory()->count(12)->for($cart)->create();
    $user = $cart->user;
    $this->actingAs($user, 'sanctum');
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(12, 'data.items');
        expect(DB::getQueryLog())->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
});

it('does not reserve stock across independent customer carts', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 5]);

    $this->withToken($first->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])->assertCreated();
    $this->app['auth']->forgetGuards();
    $this->withToken($second->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertCreated();

    $this->assertDatabaseCount('carts', 2);
    $this->assertDatabaseCount('cart_items', 2);
    expect($product->fresh()->stock_quantity)->toBe(5);
});

it('returns 409 and rolls back additions whose money total would overflow', function (int $price) {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => $price, 'stock_quantity' => 2]);

    $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
        ->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');

    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
})->with([PHP_INT_MAX]);

it('returns 409 if current prices overflow the estimate but still allows deleting the line', function () {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $item->product->update(['price_minor' => PHP_INT_MAX]);
    $token = $item->cart->user->createToken('cart-test')->plainTextToken;

    $this->withToken($token)->getJson('/api/cart')->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');
    $this->assertModelExists($item);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->deleteJson('/api/cart/items/'.$item->id)->assertNoContent();
    $this->assertModelMissing($item);
});
