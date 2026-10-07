<?php

use App\DTOs\Product\ProductQuery;
use App\DTOs\Product\UpdateProductData;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Product\ProductService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

it('allows product staff to list inactive products create edit retire and adjust stock', function (array $roles) {
    $user = User::factory()->create();
    $user->assignRole($roles);
    $inactive = Product::factory()->inactive()->create();
    $this->withToken($user->createToken('products')->plainTextToken);

    $created = $this->postJson('/api/admin/products', ['name' => 'Managed Product', 'sku' => 'STAFF-PRODUCT',
        'price_minor' => 1900, 'stock_quantity' => 10, 'status' => 'active'])->assertCreated()->json('data.id');
    $this->getJson('/api/admin/products')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/admin/products/'.$inactive->id)->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->patchJson('/api/admin/products/'.$created, ['name' => 'Updated Product', 'price_minor' => 2300,
        'status' => 'inactive', 'stock_adjustment' => -3])->assertOk()->assertJsonPath('data.stock_quantity', 7);

    $this->assertDatabaseHas('products', ['id' => $created, 'name' => 'Updated Product', 'price_minor' => 2300,
        'stock_quantity' => 7, 'status' => 'inactive']);
    $this->patchJson('/api/admin/products/'.$created, ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
})->with([
    'product manager' => [['product_manager']],
    'administrator' => [['administrator']],
    'dual manager' => [['product_manager', 'promotion_manager']],
]);

it('allows promotion staff to inspect aggregate usage create edit activate and deactivate promotions', function (array $roles) {
    $user = User::factory()->create();
    $user->assignRole($roles);
    $existing = Promotion::factory()->create();
    PromotionRedemption::factory()->for($existing)->create();
    $this->withToken($user->createToken('promotions')->plainTextToken);

    $this->getJson('/api/admin/promotions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.redemptions_count', 1);
    $this->getJson('/api/admin/promotions/'.$existing->id)->assertOk()->assertJsonPath('data.redemptions_count', 1)
        ->assertJsonMissingPath('data.redemptions')->assertJsonMissingPath('data.users');
    $created = $this->postJson('/api/admin/promotions', ['code' => 'STAFF-PROMOTION', 'type' => 'fixed',
        'value' => 100, 'is_active' => true])->assertCreated()->json('data.id');
    $this->patchJson('/api/admin/promotions/'.$created, ['value' => 200, 'is_active' => false])->assertOk()
        ->assertJsonPath('data.value', 200)->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('promotions', ['id' => $created, 'value' => 200, 'is_active' => false]);
    $this->patchJson('/api/admin/promotions/'.$created, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseCount('promotion_redemptions', 1);
})->with([
    'promotion manager' => [['promotion_manager']],
    'administrator' => [['administrator']],
    'dual manager' => [['product_manager', 'promotion_manager']],
]);

it('returns 403 before validation or lookup for the other internal domain', function (string $role, string $method, string $path) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->withToken($user->createToken('wrong-domain')->plainTextToken)->json($method, $path,
        ['roles' => ['administrator'], 'permissions' => ['*'], 'is_admin' => true, 'stock_adjustment' => 10])
        ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
})->with([
    ['product_manager', 'GET', '/api/admin/promotions'],
    ['product_manager', 'GET', '/api/admin/promotions/999999'],
    ['product_manager', 'POST', '/api/admin/promotions'],
    ['product_manager', 'PATCH', '/api/admin/promotions/999999'],
    ['promotion_manager', 'GET', '/api/admin/products'],
    ['promotion_manager', 'GET', '/api/admin/products/999999'],
    ['promotion_manager', 'POST', '/api/admin/products'],
    ['promotion_manager', 'PATCH', '/api/admin/products/999999'],
]);

it('allows metadata-only permission while requiring both product update and inventory adjustment for stock deltas', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('products.update');
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $this->withToken($user->createToken('metadata-only')->plainTextToken);

    $this->patchJson('/api/admin/products/'.$product->id, ['name' => 'Metadata Updated'])->assertOk();
    $this->patchJson('/api/admin/products/'.$product->id, ['name' => 'Forbidden Update', 'stock_adjustment' => 2])->assertForbidden();

    expect($product->fresh()->name)->toBe('Metadata Updated');
    expect($product->fresh()->stock_quantity)->toBe(10);
    $user->givePermissionTo('inventory.adjust');
    $this->app['auth']->forgetGuards();
    $this->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => 2])->assertOk()->assertJsonPath('data.stock_quantity', 12);
    $user->revokePermissionTo('products.update');
    $this->app['auth']->forgetGuards();
    $this->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => 2])->assertForbidden();

    expect($product->fresh()->stock_quantity)->toBe(12);
});

it('enforces inventory permissions on direct service updates before any partial mutation', function (int $adjustment) {
    $user = User::factory()->create();
    $user->givePermissionTo('products.update');
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $products = app(ProductService::class);

    $products->updateProduct($user, (string) $product->id, UpdateProductData::fromArray(['name' => 'Direct Metadata']));
    expect(fn () => $products->updateProduct($user, (string) $product->id,
        UpdateProductData::fromArray(['name' => 'Forbidden Direct Change', 'stock_adjustment' => $adjustment])))->toThrow(AuthorizationException::class);

    expect($product->fresh()->name)->toBe('Direct Metadata');
    expect($product->fresh()->stock_quantity)->toBe(10);
})->with(['positive adjustment' => 2, 'zero adjustment' => 0]);

it('authorizes administrative read service methods when HTTP middleware is absent', function () {
    $customer = User::factory()->create(['is_admin' => true]);
    $product = Product::factory()->inactive()->create();
    $products = app(ProductService::class);

    expect(fn () => $products->listAdminProducts($customer, new ProductQuery))->toThrow(AuthorizationException::class);
    expect(fn () => $products->getAdminProduct($customer, (string) $product->id))->toThrow(AuthorizationException::class);
});

it('grants and revokes access for the same existing bearer token without changing the legacy flag', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $token = $user->createToken('before-role')->plainTextToken;
    $this->withToken($token)->getJson('/api/admin/products')->assertForbidden();
    $user->assignRole('product_manager');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/products')->assertOk();
    $user->assignRole('promotion_manager');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/promotions')->assertOk();
    $user->removeRole('product_manager');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/admin/products')->assertForbidden();
    $this->getJson('/api/admin/promotions')->assertOk();
    expect($user->fresh()->is_admin)->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('uses current database roles despite stale eager-loaded permissions on a reused service actor', function () {
    $user = User::factory()->productManager()->create();
    $user->load('roles.permissions', 'permissions');
    $products = app(ProductService::class);
    $product = Product::factory()->create();
    $products->getAdminProduct($user, (string) $product->id);
    $user->load('roles.permissions', 'permissions');
    User::findOrFail($user->id)->removeRole('product_manager');

    expect(fn () => $products->getAdminProduct($user, (string) $product->id))->toThrow(AuthorizationException::class);
    expect(fn () => $products->updateProduct($user, (string) $product->id, UpdateProductData::fromArray(['name' => 'Forbidden'])))->toThrow(AuthorizationException::class);
});

it('uses current direct permissions despite stale eager-loaded permissions on a reused actor', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('products.update');
    $user->load('roles.permissions', 'permissions');
    expect(Gate::forUser($user)->allows('update', Product::class))->toBeTrue();
    $user->load('roles.permissions', 'permissions');
    User::findOrFail($user->id)->revokePermissionTo('products.update');

    expect(Gate::forUser($user)->allows('update', Product::class))->toBeFalse();
});

it('applies dynamic role permission changes to an existing token and reused actor', function () {
    $user = User::factory()->productManager()->create();
    $token = $user->createToken('dynamic-permission')->plainTextToken;
    $user->load('roles.permissions', 'permissions');
    $this->withToken($token)->getJson('/api/admin/products')->assertOk();
    Role::findByName('product_manager', 'web')->revokePermissionTo('products.view-admin');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/admin/products')->assertForbidden();
    expect(Gate::forUser($user)->allows('viewAny', Product::class))->toBeFalse();
    Role::findByName('product_manager', 'web')->givePermissionTo('products.view-admin');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/products')->assertOk();
});

it('keeps staff cart order checkout and cancellation access scoped to the authenticated owner', function (string $state) {
    $staff = User::factory()->$state()->create();
    $foreignItem = CartItem::factory()->create(['quantity' => 2]);
    $foreignOrder = Order::factory()->for($foreignItem->cart->user)->create();
    $this->withToken($staff->createToken('ownership')->plainTextToken);

    $this->json('GET', '/api/cart', ['user_id' => $foreignItem->cart->user_id])->assertOk()->assertJsonPath('data.items', []);
    $this->patchJson('/api/cart/items/'.$foreignItem->id, ['quantity' => 1])->assertNotFound();
    $this->deleteJson('/api/cart/items/'.$foreignItem->id)->assertNotFound();
    $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/orders/'.$foreignOrder->id)->assertNotFound();
    $this->postJson('/api/orders/'.$foreignOrder->id.'/cancel')->assertNotFound();
    $this->postJson('/api/checkout', ['user_id' => $foreignItem->cart->user_id])->assertConflict()->assertJsonPath('error.code', 'CART_EMPTY');

    expect($foreignItem->fresh()->quantity)->toBe(2);
    expect($foreignOrder->fresh()->status)->toBe(OrderStatus::Placed);
    expect(Gate::forUser($staff)->inspect('update', $foreignItem->cart)->status())->toBe(404);
    expect(Gate::forUser($staff)->inspect('cancel', $foreignOrder)->status())->toBe(404);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_outbox_events', 0);
})->with(['productManager', 'promotionManager', 'administrator']);

it('keeps ownership policies authoritative when unrelated permissions match policy ability names', function () {
    $staff = User::factory()->administrator()->create();
    foreach (['view', 'update', 'cancel'] as $ability) {
        $staff->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $foreignItem = CartItem::factory()->create();
    $foreignOrder = Order::factory()->for($foreignItem->cart->user)->create();

    expect($staff->hasPermissionTo('view'))->toBeTrue();
    expect(Gate::forUser($staff)->inspect('view', $foreignItem->cart)->status())->toBe(404);
    expect(Gate::forUser($staff)->inspect('update', $foreignItem->cart)->status())->toBe(404);
    expect(Gate::forUser($staff)->inspect('view', $foreignOrder)->status())->toBe(404);
    expect(Gate::forUser($staff)->inspect('cancel', $foreignOrder)->status())->toBe(404);
    expect($staff->can('products.view-admin'))->toBeTrue();
});

it('retains ordinary checkout and cancellation behavior for internal staff acting on their own cart', function (string $state) {
    $staff = User::factory()->$state()->create();
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 2]);
    $item->cart->user()->associate($staff)->save();

    $order = app(CheckoutService::class)->checkout($staff)->order;
    $this->withToken($staff->createToken('own-orders')->plainTextToken)->getJson('/api/orders/'.$order->id)->assertOk();
    $this->postJson('/api/orders/'.$order->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('order_outbox_events', 2);
})->with(['productManager', 'promotionManager', 'administrator']);

it('omits internal roles and permissions even when customer profile relations are loaded', function () {
    $staff = User::factory()->administrator()->create();
    $staff->load('roles.permissions', 'permissions');

    expect($staff->toArray())->not->toHaveKey('roles')->not->toHaveKey('permissions')->not->toHaveKey('is_admin');
    $this->withToken($staff->createToken('profile')->plainTextToken)->getJson('/api/auth/me')->assertOk()
        ->assertJsonMissingPath('data.roles')->assertJsonMissingPath('data.permissions')->assertJsonMissingPath('data.is_admin');
});
