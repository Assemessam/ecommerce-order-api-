<?php

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\ProductStatus;
use App\Exceptions\Domain\InactiveProductException;
use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Eloquent\EloquentCartRepository;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('binds the cart repository contract to its Eloquent implementation', function () {
    expect(app(CartRepositoryInterface::class))->toBeInstanceOf(EloquentCartRepository::class);
});

it('rejects invalid quantities at the service boundary without invoking repositories', function (int $quantity) {
    $carts = Mockery::mock(CartRepositoryInterface::class);
    $products = Mockery::mock(ProductRepositoryInterface::class);
    $carts->shouldNotReceive('createOrLockForUser');
    $carts->shouldNotReceive('lockForUser');
    $service = new CartService($carts, $products, app(CartPricingService::class));
    $user = User::factory()->make();

    expect(fn () => $service->addItem($user, 1, $quantity))->toThrow(ValidationException::class);
    expect(fn () => $service->updateItem($user, '1', $quantity))->toThrow(ValidationException::class);
})->with([0, -1]);

it('acquires the cart before the product and validates stock in the service', function () {
    $cart = Cart::factory()->make(['id' => 1, 'user_id' => 11]);
    $product = Product::factory()->make(['id' => 2, 'stock_quantity' => 4]);
    $user = User::factory()->make(['id' => 11]);
    $carts = Mockery::mock(CartRepositoryInterface::class);
    $products = Mockery::mock(ProductRepositoryInterface::class);
    $carts->shouldReceive('createOrLockForUser')->once()->with($user)->globally()->ordered()->andReturn($cart);
    $products->shouldReceive('findByIdForUpdate')->once()->with(2)->globally()->ordered()->andReturn($product);
    $carts->shouldReceive('findItemByProduct')->once()->with($cart, 2)->globally()->ordered()->andReturnNull();
    $carts->shouldNotReceive('createItem');
    $service = new CartService($carts, $products, app(CartPricingService::class));

    expect(fn () => $service->addItem($user, 2, 5))->toThrow(InsufficientStockException::class);
});

it('rejects inactive products in the service', function () {
    $cart = Cart::factory()->make(['id' => 1, 'user_id' => 11]);
    $product = Product::factory()->inactive()->make(['id' => 2]);
    $user = User::factory()->make(['id' => 11]);
    $carts = Mockery::mock(CartRepositoryInterface::class);
    $products = Mockery::mock(ProductRepositoryInterface::class);
    $carts->shouldReceive('createOrLockForUser')->once()->with($user)->andReturn($cart);
    $products->shouldReceive('findByIdForUpdate')->once()->with(2)->andReturn($product);
    $carts->shouldNotReceive('createItem');

    expect(fn () => (new CartService($carts, $products, app(CartPricingService::class)))->addItem($user, 2, 1))->toThrow(InactiveProductException::class);
});

it('rolls back the cart and inserted line if a later operation fails', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 5]);
    Event::listen('eloquent.created: '.CartItem::class, function (CartItem $item): void {
        throw new RuntimeException('Injected failure after item creation.');
    });

    expect(fn () => app(CartService::class)->addItem($user, $product->id, 2))->toThrow(RuntimeException::class, 'Injected failure');

    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
    expect($product->fresh()->stock_quantity)->toBe(5);
});

it('maps database integrity failures to safe 409 errors after rolling back', function () {
    config(['app.debug' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Event::listen('eloquent.created: '.CartItem::class, function (CartItem $item): void {
        DB::table('cart_items')->where('id', $item->id)->update(['quantity' => 0]);
    });

    $response = $this->withToken($user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])
        ->assertConflict()->assertJsonPath('error.code', 'CART_CONFLICT')
        ->assertJsonMissingPath('error.details')->assertJsonMissingPath('exception');

    expect($response->getContent())->not->toContain('SQLSTATE', 'cart_items_quantity_positive', 'trace');
    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-ID'));
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseCount('cart_items', 0);
});

it('rolls back an update if the resulting subtotal exceeds the integer range', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => PHP_INT_MAX, 'stock_quantity' => 2]))->create();

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->patchJson('/api/cart/items/'.$item->id, ['quantity' => 2])
        ->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');

    expect($item->fresh()->quantity)->toBe(1);
});

it('rolls back a new line when the sum of exact line amounts exceeds the integer range', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => PHP_INT_MAX]))->create();
    $newProduct = Product::factory()->create(['price_minor' => 1]);

    $this->withToken($item->cart->user->createToken('cart-test')->plainTextToken)->postJson('/api/cart/items', ['product_id' => $newProduct->id, 'quantity' => 1])
        ->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');

    $this->assertDatabaseCount('cart_items', 1);
    $this->assertModelExists($item);
});

it('accepts maximum bigint quantities without addition overflow and leaves stock unchanged', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price_minor' => 0, 'stock_quantity' => PHP_INT_MAX]);
    $service = app(CartService::class);
    $service->addItem($user, $product->id, PHP_INT_MAX);

    expect(fn () => $service->addItem($user, $product->id, 1))->toThrow(InsufficientStockException::class);

    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => PHP_INT_MAX]);
    expect($product->fresh()->stock_quantity)->toBe(PHP_INT_MAX);
});

it('allows reducing an item to the remaining stock after a stock change', function () {
    $item = CartItem::factory()->create(['quantity' => 4]);
    $item->product->update(['stock_quantity' => 2, 'status' => ProductStatus::Active]);

    $view = app(CartService::class)->updateItem($item->cart->user, (string) $item->id, 2);

    expect($view->items->sole()->isAvailable)->toBeTrue();
    expect($item->fresh()->quantity)->toBe(2);
});

it('rejects further additions when the existing line already exceeds changed stock', function () {
    $item = CartItem::factory()->create(['quantity' => 4]);
    $item->product->update(['stock_quantity' => 2]);

    expect(fn () => app(CartService::class)->addItem($item->cart->user, $item->product_id, 1))
        ->toThrow(InsufficientStockException::class);

    expect($item->fresh()->quantity)->toBe(4);
    expect($item->product->fresh()->stock_quantity)->toBe(2);
});

it('returns 404 if a repository mistakenly supplies a non-owned cart', function () {
    $user = User::factory()->create();
    $otherCart = Cart::factory()->create();
    $carts = Mockery::mock(CartRepositoryInterface::class);
    $carts->shouldReceive('findForUser')->once()->with(Mockery::on(fn (User $customer): bool => $customer->id === $user->id))->andReturn($otherCart);
    $this->app->instance(CartRepositoryInterface::class, $carts);

    $this->withToken($user->createToken('cart-test')->plainTextToken)->getJson('/api/cart')
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

    $this->assertModelExists($otherCart);
});
