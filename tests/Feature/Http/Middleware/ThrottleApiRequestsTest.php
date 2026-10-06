<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

it('applies the documented named policy to each public customer and admin endpoint', function (string $method, string $path, string $policy, int $limit, int $status, string $role) {
    if ($role !== 'public') {
        $user = $role === 'admin' ? User::factory()->administrator()->create() : User::factory()->create();
        $this->withToken($user->createToken('rate-test')->plainTextToken);
    }
    $this->json($method, $path)->assertStatus($status)->assertHeader('X-RateLimit-Limit', (string) $limit)
        ->assertHeader('X-RateLimit-Remaining', (string) ($limit - 1));
    config(['rate-limits.policies.'.$policy.'.attempts' => 1]);
    $denied = $this->json($method, $path)->assertTooManyRequests()->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After')->assertHeader('X-RateLimit-Reset');
    expect((int) $denied->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual($policy === 'auth-register' ? 3600 : 60);

})->with([
    'registration' => ['POST', '/api/auth/register', 'auth-register', 5, 422, 'public'],
    'catalogue listing' => ['GET', '/api/products', 'catalogue-read', 120, 200, 'public'],
    'catalogue detail' => ['GET', '/api/products/999999', 'catalogue-read', 120, 404, 'public'],
    'catalogue HEAD' => ['HEAD', '/api/products', 'catalogue-read', 120, 200, 'public'],
    'health' => ['GET', '/api/health', 'health-read', 120, 200, 'public'],
    'cart read' => ['GET', '/api/cart', 'cart-read', 120, 200, 'customer'],
    'cart add' => ['POST', '/api/cart/items', 'cart-mutation', 60, 422, 'customer'],
    'cart update' => ['PATCH', '/api/cart/items/999999', 'cart-mutation', 60, 422, 'customer'],
    'cart remove' => ['DELETE', '/api/cart/items/999999', 'cart-mutation', 60, 404, 'customer'],
    'promotion apply' => ['POST', '/api/cart/promotion', 'promotion-mutation', 30, 422, 'customer'],
    'promotion remove' => ['DELETE', '/api/cart/promotion', 'promotion-mutation', 30, 204, 'customer'],
    'checkout' => ['POST', '/api/checkout', 'checkout', 20, 409, 'customer'],
    'order history' => ['GET', '/api/orders', 'order-read', 120, 200, 'customer'],
    'order detail' => ['GET', '/api/orders/999999', 'order-read', 120, 404, 'customer'],
    'order cancellation' => ['POST', '/api/orders/999999/cancel', 'order-cancel', 20, 404, 'customer'],
    'account read' => ['GET', '/api/auth/me', 'account-read', 120, 200, 'customer'],
    'admin product list' => ['GET', '/api/admin/products', 'admin-read', 120, 200, 'admin'],
    'admin product detail' => ['GET', '/api/admin/products/999999', 'admin-read', 120, 404, 'admin'],
    'admin product create' => ['POST', '/api/admin/products', 'admin-mutation', 30, 422, 'admin'],
    'admin product update' => ['PATCH', '/api/admin/products/999999', 'admin-mutation', 30, 404, 'admin'],
    'admin promotion list' => ['GET', '/api/admin/promotions', 'admin-read', 120, 200, 'admin'],
    'admin promotion detail' => ['GET', '/api/admin/promotions/999999', 'admin-read', 120, 404, 'admin'],
    'admin promotion create' => ['POST', '/api/admin/promotions', 'admin-mutation', 30, 422, 'admin'],
    'admin promotion update' => ['PATCH', '/api/admin/promotions/999999', 'admin-mutation', 30, 404, 'admin'],
]);

it('shares catalogue allowance across listing detail and HEAD while keeping health separate', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 2]);
    $product = Product::factory()->create();
    $this->getJson('/api/products')->assertOk();
    $this->getJson('/api/products/'.$product->id)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->head('/api/products')->assertTooManyRequests();
    $this->getJson('/api/health')->assertOk();
});

it('returns truthful native Retry-After reset remaining and window expiration headers', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 2]);
    $this->getJson('/api/products')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
    $this->getJson('/api/products')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $before = time();
    $response = $this->getJson('/api/products')->assertTooManyRequests();
    $response->assertExactJson(['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests. Please try again later.',
        'request_id' => $response->headers->get('X-Request-ID')]]);
    $after = time();
    $retry = (int) $response->headers->get('Retry-After');
    $reset = (int) $response->headers->get('X-RateLimit-Reset');
    expect($retry)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($reset)->toBeGreaterThanOrEqual($before + $retry)->toBeLessThanOrEqual($after + $retry);
    $redis = Redis::connection('rate-limits');
    $key = substr($redis->keys('*')[0], strlen($this->rateLimitPrefix));
    expect((int) $redis->hget($key, 'end') - (int) $redis->hget($key, 'start'))->toBe(60);
    expect($redis->ttl($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
    $this->advanceRateLimitWindows();
    $this->getJson('/api/products')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
});

it('counts invalid registration attempts for one hour and protects writes when exhausted', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/auth/register', [])->assertUnprocessable();
    }
    $response = $this->postJson('/api/auth/register', ['name' => 'Rate Test', 'email' => 'rate@example.com',
        'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!'])->assertTooManyRequests();
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->advanceRateLimitWindows();
    $this->postJson('/api/auth/register', [])->assertUnprocessable();
});

it('blocks account-targeted login attempts across IPs using a normalized hashed identity', function () {
    $user = User::factory()->create(['email' => 'target@example.com']);
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->postJson('/api/auth/login',
            ['email' => $attempt % 2 === 0 ? ' TARGET@EXAMPLE.COM ' : 'target@example.com', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson('/api/auth/login',
        ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('X-RateLimit-Limit', '20');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->postJson('/api/auth/login', ['email' => 'other@example.com', 'password' => 'wrong'])->assertUnauthorized();
    $redis = Redis::connection('rate-limits');
    foreach ($redis->keys('*') as $key) {
        expect($key)->not->toContain('target', '@', 'example.com');
        expect($redis->hgetall(substr($key, strlen($this->rateLimitPrefix))))->toHaveKeys(['start', 'end', 'count']);
    }
    $this->advanceRateLimitWindows();
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('counts non-string login identifiers and cannot bypass the IP limit with malformed input', function () {
    config(['rate-limits.login.ip_per_minute' => 2]);
    $this->postJson('/api/auth/login', ['email' => []])->assertUnprocessable();
    $this->postJson('/api/auth/login', ['email' => ['user_id' => 99]])->assertUnprocessable();
    $this->postJson('/api/auth/login', ['email' => null])->assertTooManyRequests();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('isolates customers by authenticated ID across tokens IPs and supplied body identities', function () {
    config(['rate-limits.policies.cart-read.attempts' => 1]);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $this->withToken($first->createToken('first')->plainTextToken)->getJson('/api/cart')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])->withToken($first->createToken('rotated')->plainTextToken)
        ->json('GET', '/api/cart', ['user_id' => $second->id, 'is_admin' => true])->assertTooManyRequests();
    $this->app['auth']->forgetGuards();
    $this->withToken($second->createToken('second')->plainTextToken)->getJson('/api/cart')->assertOk();
});

it('keeps reads sensitive mutations promotions orders and administrator policies independent', function () {
    foreach (['cart-read', 'cart-mutation', 'promotion-mutation', 'order-read', 'admin-read', 'admin-mutation'] as $policy) {
        config(['rate-limits.policies.'.$policy.'.attempts' => 1]);
    }
    $user = User::factory()->administrator()->create();
    $this->withToken($user->createToken('admin')->plainTextToken);
    $this->getJson('/api/cart')->assertOk();
    $this->getJson('/api/cart')->assertTooManyRequests();
    $this->postJson('/api/cart/items', [])->assertUnprocessable();
    $this->postJson('/api/cart/items', [])->assertTooManyRequests();
    $this->postJson('/api/cart/promotion', [])->assertUnprocessable();
    $this->getJson('/api/orders')->assertOk();
    $this->getJson('/api/admin/products')->assertOk();
    $this->getJson('/api/admin/promotions')->assertTooManyRequests();
    $this->postJson('/api/admin/products', [])->assertUnprocessable();
    $this->postJson('/api/admin/promotions', [])->assertTooManyRequests();
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
});

it('returns 401 before authenticated limits for guests spoofing identity and never touches Redis', function (string $method, string $path) {
    $this->json($method, $path, ['user_id' => 1, 'is_admin' => true])->assertUnauthorized();
    expect(Redis::connection('rate-limits')->keys('*'))->toBe([]);
})->with([
    ['GET', '/api/cart'], ['POST', '/api/checkout'], ['GET', '/api/orders'], ['POST', '/api/orders/1/cancel'],
    ['GET', '/api/admin/products'], ['POST', '/api/admin/products'],
]);

it('keeps normal forbidden admin access and documents throttle precedence for repeated abuse', function () {
    config(['rate-limits.policies.admin-read.attempts' => 1]);
    $user = User::factory()->create();
    $this->withToken($user->createToken('customer')->plainTextToken)->getJson('/api/admin/products')->assertForbidden();
    $this->getJson('/api/admin/products')->assertTooManyRequests();
    $this->app['auth']->forgetGuards();
    $admin = User::factory()->administrator()->create();
    $this->withToken($admin->createToken('admin')->plainTextToken)->getJson('/api/admin/products')->assertOk();
});

it('ignores spoofed forwarding headers from untrusted peers including provider-shaped Host headers', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->getJson('/api/products', ['X-Forwarded-For' => '198.51.100.1'])->assertOk();
    $this->getJson('/api/products', ['X-Forwarded-For' => '198.51.100.2', 'Forwarded' => 'for=198.51.100.3',
        'Host' => 'fake.on-forge.com'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.51'])->getJson('/api/products')->assertOk();
});

it('uses the forwarded client IP only through the configured trusted proxy allowlist', function () {
    config(['trustedproxy.proxies' => ['192.0.2.60'], 'rate-limits.policies.catalogue-read.attempts' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.60'])->getJson('/api/products', ['X-Forwarded-For' => '198.51.100.1'])->assertOk();
    $this->getJson('/api/products', ['X-Forwarded-For' => '198.51.100.1'])->assertTooManyRequests();
    $this->getJson('/api/products', ['X-Forwarded-For' => '198.51.100.2'])->assertOk();
});

it('admits logout by user identity across tokens and preserves a throttled token', function () {
    config(['rate-limits.policies.account-mutation.attempts' => 1]);
    $user = User::factory()->create();
    $first = $user->createToken('first');
    $second = $user->createToken('second');
    $this->withToken($first->plainTextToken)->postJson('/api/auth/logout')->assertNoContent();
    $this->assertModelMissing($first->accessToken);
    $this->app['auth']->forgetGuards();
    $this->withToken($second->plainTextToken)->postJson('/api/auth/logout')->assertTooManyRequests();
    $this->assertModelExists($second->accessToken);
});

it('prevents every checkout side effect when throttled and preserves idempotency after window reset', function () {
    config(['rate-limits.policies.checkout.attempts' => 1]);
    $user = User::factory()->create();
    $this->withToken($user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')->assertConflict();
    $product = Product::factory()->create(['price_minor' => 1000, 'stock_quantity' => 10]);
    $cart = Cart::factory()->for($user)->create();
    $item = CartItem::factory()->for($cart)->for($product)->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited()->create();
    $user->cart->promotion()->associate($promotion)->save();
    $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'throttled-first'])->assertTooManyRequests();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertModelExists($item);
    expect($product->fresh()->stock_quantity)->toBe(10);
    expect($user->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->advanceRateLimitWindows();
    $order = $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'throttled-first'])->assertCreated();
    expect($product->fresh()->stock_quantity)->toBe(8);
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $newItem = CartItem::factory()->for($user->cart)->for($product)->create();
    $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'throttled-first'])->assertTooManyRequests();
    $this->advanceRateLimitWindows();
    $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'throttled-first'])->assertOk()->assertExactJson($order->json());
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $this->assertModelExists($newItem);
    expect($product->fresh()->stock_quantity)->toBe(8);
});

it('prevents throttled cancellation from changing inventory order markers or outbox history', function () {
    config(['rate-limits.policies.order-cancel.attempts' => 1]);
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $user = $item->cart->user;
    $this->withToken($user->createToken('cancel')->plainTextToken);
    $order = $this->postJson('/api/checkout')->assertCreated()->json('data.id');
    $this->postJson('/api/orders/999999/cancel')->assertNotFound();
    $this->postJson('/api/orders/'.$order.'/cancel')->assertTooManyRequests();
    expect(Order::findOrFail($order)->cancelled_at)->toBeNull();
    expect($item->product->fresh()->stock_quantity)->toBe(8);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $this->advanceRateLimitWindows();
    $this->postJson('/api/orders/'.$order.'/cancel')->assertOk();
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('order_outbox_events', 2);
});

it('fails closed with sanitized 503 on a real refused Redis connection before checkout effects', function () {
    config(['database.redis.rate-limits-outage' => array_replace(config('database.redis.rate-limits'), ['port' => 6380]),
        'rate-limits.connection' => 'rate-limits-outage']);
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $this->withToken($item->cart->user->createToken('outage')->plainTextToken);
    $response = $this->postJson('/api/checkout')->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    $response->assertExactJson(['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'The service is temporarily unavailable.',
        'request_id' => $response->headers->get('X-Request-ID')]]);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertModelExists($item);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    config(['rate-limits.connection' => 'rate-limits']);
    $this->postJson('/api/checkout')->assertCreated();
});

it('uses one atomic Redis operation per admitted or rejected single-policy request', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 1]);
    $operations = [];
    Redis::connection('rate-limits')->setEventDispatcher(app('events'));
    Redis::connection('rate-limits')->listen(function ($event) use (&$operations): void {
        $operations[] = $event->command;
    });
    $this->getJson('/api/products')->assertOk();
    $this->getJson('/api/products')->assertTooManyRequests();
    expect($operations)->toBe(['eval', 'eval']);
});

it('treats equivalent IPv6 representations as one public identity and rejects invalid peer addresses', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->getJson('/api/products')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:0db8:0000:0000:0000:0000:0000:0001'])->getJson('/api/products')->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => 'invalid'])->getJson('/api/products')->assertBadRequest();
});

it('returns the existing JSON 429 envelope without an Accept header', function () {
    config(['rate-limits.policies.catalogue-read.attempts' => 1]);
    $this->get('/api/products')->assertOk();
    $response = $this->get('/api/products')->assertTooManyRequests()->assertHeader('Content-Type', 'application/json');
    $response->assertExactJson(['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests. Please try again later.',
        'request_id' => $response->headers->get('X-Request-ID')]]);
});

it('allows valid cart and promotion changes while refusing later writes before their services run', function () {
    config(['rate-limits.policies.cart-mutation.attempts' => 1, 'rate-limits.policies.promotion-mutation.attempts' => 1]);
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $promotion = Promotion::factory()->create();
    $this->withToken($user->createToken('cart')->plainTextToken);
    $line = $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
    $item = CartItem::query()->sole();
    $this->patchJson('/api/cart/items/'.$item->id, ['quantity' => 3])->assertTooManyRequests();
    expect($item->fresh()->quantity)->toBe(2);
    $this->postJson('/api/cart/promotion', ['code' => $promotion->code])->assertOk();
    $this->deleteJson('/api/cart/promotion')->assertTooManyRequests();
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('isolates administrator allowances and blocks a valid exhausted administrative mutation', function () {
    config(['rate-limits.policies.admin-mutation.attempts' => 1]);
    $first = User::factory()->administrator()->create();
    $second = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Unchanged']);
    $this->withToken($first->createToken('first')->plainTextToken)->postJson('/api/admin/products', [])->assertUnprocessable();
    $this->patchJson('/api/admin/products/'.$product->id, ['name' => 'Blocked'])->assertTooManyRequests();
    expect($product->fresh()->name)->toBe('Unchanged');
    $this->app['auth']->forgetGuards();
    $this->withToken($second->createToken('second')->plainTextToken)->patchJson('/api/admin/products/'.$product->id, ['name' => 'Allowed'])->assertOk();
    expect($product->fresh()->name)->toBe('Allowed');
});
