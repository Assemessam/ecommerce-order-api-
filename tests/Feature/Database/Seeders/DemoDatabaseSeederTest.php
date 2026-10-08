<?php

use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\PromotionIneligibilityReason;
use App\Enums\PromotionType;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderOutboxEvent;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\OrderService;
use App\Services\Promotion\PromotionService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCartSeeder;
use Database\Seeders\DemoDatabaseSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\DemoProductSeeder;
use Database\Seeders\DemoPromotionSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['demo.password' => 'LocalDemoTest123!']);
});

/** @return array<string, list<array<string, mixed>>> */
function demoSeederDatabaseState(): array
{
    $state = [];

    foreach ([
        'users' => ['id'],
        'products' => ['id'],
        'promotions' => ['id'],
        'carts' => ['id'],
        'cart_items' => ['id'],
        'orders' => ['id'],
        'order_items' => ['id'],
        'promotion_redemptions' => ['id'],
        'order_outbox_events' => ['id'],
        'order_notifications' => ['id'],
        'roles' => ['id'],
        'permissions' => ['id'],
        'role_has_permissions' => ['role_id', 'permission_id'],
        'model_has_roles' => ['model_type', 'model_id', 'role_id'],
        'model_has_permissions' => ['model_type', 'model_id', 'permission_id'],
    ] as $table => $columns) {
        $query = DB::table($table);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        $state[$table] = $query->get()->map(fn (stdClass $record): array => (array) $record)->all();
    }

    return $state;
}

it('populates interconnected business fixtures without tokens jobs or notification delivery', function () {
    $this->freezeTime();
    Queue::fake();
    Mail::fake();
    Notification::fake();

    $this->seed(DemoDatabaseSeeder::class);

    foreach ([
        'users' => 8,
        'products' => 16,
        'promotions' => 9,
        'carts' => 5,
        'cart_items' => 3,
        'orders' => 7,
        'order_items' => 10,
        'promotion_redemptions' => 5,
        'order_outbox_events' => 8,
        'order_notifications' => 0,
        'roles' => 3,
        'permissions' => 7,
        'role_has_permissions' => 14,
        'model_has_roles' => 3,
        'model_has_permissions' => 0,
        'personal_access_tokens' => 0,
        'password_reset_tokens' => 0,
        'sessions' => 0,
        'jobs' => 0,
        'job_batches' => 0,
        'failed_jobs' => 0,
        'cache' => 0,
        'cache_locks' => 0,
    ] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }

    foreach (User::query()->get() as $user) {
        expect($user->name)->toStartWith('Demo | ');
        expect($user->email)->toEndWith('@example.test');
        expect(Hash::check('LocalDemoTest123!', $user->password))->toBeTrue();
        expect($user->is_admin)->toBeFalse();
    }

    foreach (OrderOutboxEvent::query()->get() as $event) {
        $this->assertDatabaseHas('orders', ['id' => $event->order_id]);
        expect($event->status)->toBe(OrderOutboxStatus::Pending);
        expect($event->dispatch_token)->toBeNull();
        expect($event->dispatch_attempts)->toBe(0);
        expect($event->processing_attempts)->toBe(0);
        expect($event->processed_at)->toBeNull();
        expect($event->failed_at)->toBeNull();
    }

    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('creates supported product scenarios with accurate inventory after checkout and cancellation', function () {
    $this->seed(DemoDatabaseSeeder::class);

    foreach ([
        'KEYBOARD' => [4999, 49],
        'MOUSE' => [2499, 78],
        'HEADPHONES' => [12999, 19],
        'MONITOR' => [34999, 9],
        'MUG' => [1899, 40],
        'STAND' => [2999, 24],
        'CABLE' => [999, 98],
        'BACKPACK' => [6500, 29],
        'NOTEBOOK' => [499, 197],
        'ORGANIZER' => [1799, 33],
        'LAMP' => [3499, 0],
        'LIMITED' => [1299, 2],
        'SPEAKER' => [7999, 18],
        'HUB' => [5999, 25],
        'CHARGER' => [3999, 7],
        'MAT' => [1999, 0],
    ] as $sku => [$priceMinor, $stock]) {
        $product = Product::query()->where('sku', 'DEMO-V1-'.$sku)->sole();

        expect($product->price_minor)->toBe($priceMinor);
        expect($product->stock_quantity)->toBe($stock);
    }

    expect(Product::query()->where('status', ProductStatus::Active)->count())->toBe(14);
    expect(Product::query()->where('status', ProductStatus::Inactive)->count())->toBe(2);
    expect(Product::query()->pluck('sku')->unique()->count())->toBe(16);
});

it('preserves customer ownership integer totals and product and promotion snapshots in every order', function () {
    $this->seed(DemoDatabaseSeeder::class);

    foreach ([
        'standard' => ['alex', 9997, 0, 9997, 2, null],
        'percentage' => ['alex', 12999, 1300, 11699, 1, 'WELCOME10'],
        'cancelled' => ['maya', 3798, 380, 3418, 1, 'WELCOME10'],
        'fixed' => ['maya', 4997, 500, 4497, 2, 'SAVE5'],
        'historical' => ['omar', 7997, 0, 7997, 2, null],
        'limited' => ['omar', 3598, 500, 3098, 1, 'LIMITED5'],
        'capped' => ['omar', 34999, 5000, 29999, 1, 'CAPPED20'],
    ] as $scenario => [$customer, $subtotal, $discount, $total, $itemCount, $code]) {
        $order = Order::query()->with(['user', 'items.product', 'promotion', 'redemption'])
            ->where('idempotency_key', 'demo-v1:'.$scenario)->sole();

        expect($order->user->email)->toBe('demo-'.$customer.'@example.test');
        expect($order->subtotal_minor)->toBe($subtotal);
        expect($order->discount_minor)->toBe($discount);
        expect($order->total_minor)->toBe($total);
        expect($order->currency)->toBe(config('catalogue.currency'));
        expect($order->status)->toBe($scenario === 'cancelled' ? OrderStatus::Cancelled : OrderStatus::Placed);
        expect($order->items)->toHaveCount($itemCount);
        expect($order->items->sum('line_subtotal_minor'))->toBe($subtotal);
        expect($order->promotion_code_snapshot)->toBe($code === null ? null : 'DEMO-V1-'.$code);

        foreach ($order->items as $item) {
            expect($item->order_id)->toBe($order->id);
            expect($item->quantity)->toBeInt()->toBeGreaterThan(0);
            expect($item->product_name)->toBe($item->product->name);
            expect($item->product_sku)->toBe($item->product->sku);
            expect($item->unit_price_minor)->toBe($item->product->price_minor);
            expect($item->line_subtotal_minor)->toBe($item->quantity * $item->unit_price_minor);
        }

        if ($code === null) {
            expect($order->promotion_id)->toBeNull();
            expect($order->promotion_type_snapshot)->toBeNull();
            expect($order->promotion_value_snapshot)->toBeNull();
            expect($order->promotion_maximum_discount_minor_snapshot)->toBeNull();
            expect($order->redemption)->toBeNull();

            continue;
        }

        expect($order->promotion_type_snapshot)->toBe($order->promotion->type);
        expect($order->promotion_value_snapshot)->toBe($order->promotion->value);
        expect($order->promotion_maximum_discount_minor_snapshot)->toBe($order->promotion->maximum_discount_minor);
        expect($order->redemption->user_id)->toBe($order->user_id);
        expect($order->redemption->promotion_id)->toBe($order->promotion_id);
        expect($order->redemption->discount_minor)->toBe($discount);
        expect($order->redemption->redeemed_at->equalTo($order->placed_at))->toBeTrue();
    }
});

it('keeps historical timestamps coherent and restores cancelled inventory only once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T12:34:56.123456Z'));
    $historicalAt = now('UTC')->subDays(45);
    $historicalTimestamp = $historicalAt->copy()->startOfSecond();
    $this->seed(DemoDatabaseSeeder::class);
    $historical = Order::query()->with(['user', 'items.product'])->where('idempotency_key', 'demo-v1:historical')->sole();
    $cancelled = Order::query()->with('user')->where('idempotency_key', 'demo-v1:cancelled')->sole();
    $mug = Product::query()->where('sku', 'DEMO-V1-MUG')->sole();
    $state = demoSeederDatabaseState();

    app(OrderService::class)->cancelOrder($cancelled->user, (string) $cancelled->id);

    expect(demoSeederDatabaseState())->toBe($state);
    expect($mug->fresh()->stock_quantity)->toBe(40);
    expect($cancelled->cancelled_at->equalTo($cancelled->inventory_restored_at))->toBeTrue();
    expect($historical->placed_at->equalTo($historicalAt))->toBeTrue();
    expect($historical->created_at->equalTo($historicalTimestamp))->toBeTrue();
    expect($historical->user->created_at->lte($historicalAt))->toBeTrue();

    foreach ($historical->items as $item) {
        expect($item->created_at->equalTo($historicalTimestamp))->toBeTrue();
        expect($item->product->created_at->lte($historicalAt))->toBeTrue();
    }

    $placedEvent = OrderOutboxEvent::query()->where('order_id', $historical->id)->sole();
    expect($placedEvent->event_type)->toBe(OrderEventType::OrderPlaced);
    expect($placedEvent->occurred_at->equalTo($historicalAt))->toBeTrue();
    expect($placedEvent->occurred_at->equalTo($historical->placed_at))->toBeTrue();
    expect($placedEvent->created_at->equalTo($historicalAt))->toBeTrue();
    expect($placedEvent->available_at->equalTo(now('UTC')))->toBeTrue();
    expect(OrderOutboxEvent::query()->where('order_id', $cancelled->id)->count())->toBe(2);
    expect(PromotionRedemption::query()->where('order_id', $cancelled->id)->count())->toBe(1);
});

it('creates active expired future inactive minimum capped and usage limited promotions', function () {
    $this->freezeTime();
    $this->seed(DemoDatabaseSeeder::class);
    $customer = User::query()->where('email', 'demo-omar@example.test')->sole();
    $otherCustomer = User::query()->where('email', 'demo-lina@example.test')->sole();
    $promotions = app(PromotionService::class);

    foreach ([
        'WELCOME10' => [20000, $customer->id, null],
        'SAVE5' => [20000, $customer->id, null],
        'MINIMUM10' => [9999, $customer->id, PromotionIneligibilityReason::MinimumNotMet],
        'CAPPED20' => [34999, $customer->id, null],
        'EXPIRED10' => [20000, $customer->id, PromotionIneligibilityReason::Expired],
        'FUTURE10' => [20000, $customer->id, PromotionIneligibilityReason::NotStarted],
        'INACTIVE10' => [20000, $customer->id, PromotionIneligibilityReason::Inactive],
        'LIMITED5' => [20000, $customer->id, PromotionIneligibilityReason::CustomerLimitReached],
        'HIGHMIN15' => [20000, $customer->id, PromotionIneligibilityReason::MinimumNotMet],
    ] as $code => [$subtotal, $customerId, $reason]) {
        $promotion = $promotions->resolveCode('DEMO-V1-'.$code);

        expect($promotions->eligibility($promotion, $customerId, $subtotal, true, true)->reason)->toBe($reason);
    }

    $welcome = $promotions->resolveCode('DEMO-V1-WELCOME10');
    $fixed = $promotions->resolveCode('DEMO-V1-SAVE5');
    $limited = $promotions->resolveCode('DEMO-V1-LIMITED5');
    expect($welcome->type)->toBe(PromotionType::Percentage);
    expect($welcome->value)->toBe(1000);
    expect($welcome->redemptions()->count())->toBe(2);
    expect($fixed->type)->toBe(PromotionType::Fixed);
    expect($fixed->value)->toBe(500);
    expect($limited->global_usage_limit)->toBe(2);
    expect($limited->per_customer_usage_limit)->toBe(1);
    expect($limited->redemptions()->count())->toBe(1);
    expect($promotions->eligibility($limited, $otherCustomer->id, 20000, true, true)->isEligible())->toBeTrue();
});

it('creates owned available carts and a valid fixed promotion without reserving stock', function () {
    $this->seed(DemoDatabaseSeeder::class);
    $lina = User::query()->where('email', 'demo-lina@example.test')->sole();
    $noah = User::query()->where('email', 'demo-noah@example.test')->sole();

    $linaCart = app(CartService::class)->getCart($lina);
    $noahCart = app(CartService::class)->getCart($noah);

    expect($linaCart->items->pluck('product.sku')->all())->toBe(['DEMO-V1-KEYBOARD', 'DEMO-V1-HUB']);
    expect($linaCart->subtotalMinor)->toBe(10998);
    expect($linaCart->promotion)->toBeNull();
    expect($noahCart->items->pluck('product.sku')->all())->toBe(['DEMO-V1-LIMITED']);
    expect($noahCart->subtotalMinor)->toBe(1299);
    expect($noahCart->estimatedDiscountMinor)->toBe(500);
    expect($noahCart->estimatedTotalMinor)->toBe(799);
    expect($noahCart->promotion->code)->toBe('DEMO-V1-SAVE5');
    expect($noahCart->promotionEligibility->isEligible())->toBeTrue();

    foreach (Cart::query()->with(['user', 'items.product'])->get() as $cart) {
        expect($cart->user)->not->toBeNull();

        foreach ($cart->items as $item) {
            expect($item->product->status)->toBe(ProductStatus::Active);
            expect($item->quantity)->toBeLessThanOrEqual($item->product->stock_quantity);
        }
    }

    expect(Product::query()->where('sku', 'DEMO-V1-HUB')->sole()->stock_quantity)->toBe(25);
    expect(Product::query()->where('sku', 'DEMO-V1-LIMITED')->sole()->stock_quantity)->toBe(2);
});

it('assigns canonical staff permissions while customers remain roleless', function () {
    $this->seed(DemoDatabaseSeeder::class);

    foreach ([
        'admin' => 'administrator',
        'products' => 'product_manager',
        'promotions' => 'promotion_manager',
    ] as $account => $role) {
        $user = User::query()->where('email', 'demo-'.$account.'@example.test')->sole();

        expect($user->getRoleNames()->all())->toBe([$role]);
        expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect(AuthorizationSeeder::ROLE_PERMISSIONS[$role])->sort()->values()->all());
        expect($user->permissions()->count())->toBe(0);
    }

    foreach (['alex', 'maya', 'omar', 'lina', 'noah'] as $account) {
        $customer = User::query()->where('email', 'demo-'.$account.'@example.test')->sole();

        expect($customer->roles()->count())->toBe(0);
        expect($customer->permissions()->count())->toBe(0);

        foreach (AuthorizationSeeder::PERMISSIONS as $permission) {
            expect($customer->can($permission))->toBeFalse();
        }
    }
});

it('authenticates each demo account through the existing login API', function (string $account) {
    $this->seed(DemoDatabaseSeeder::class);
    $email = 'demo-'.$account.'@example.test';
    $user = User::query()->where('email', $email)->sole();

    $response = $this->postJson('/api/auth/login', [
        'email' => ' '.strtoupper($email).' ',
        'password' => 'LocalDemoTest123!',
        'device_name' => 'Seeder verification',
    ])->assertOk()->assertJsonPath('data.user.id', $user->id);

    $this->withToken($response->json('data.token'))->getJson('/api/auth/me')
        ->assertOk()->assertJsonPath('data.email', $email);
    $this->assertDatabaseCount('personal_access_tokens', 1);
})->with(['admin', 'products', 'promotions', 'alex', 'maya', 'omar', 'lina', 'noah']);

it('keeps every business and authorization record unchanged when seeded again', function () {
    $this->freezeTime();
    $this->seed(DemoDatabaseSeeder::class);
    $state = demoSeederDatabaseState();
    $this->travel(10)->days();

    $this->seed(DemoDatabaseSeeder::class);

    expect(demoSeederDatabaseState())->toBe($state);
});

it('preserves edited credentials roles catalogue promotions carts orders and unrelated records on rerun', function () {
    $this->freezeTime();
    $this->seed(DemoDatabaseSeeder::class);
    Product::query()->where('sku', 'DEMO-V1-KEYBOARD')->sole()->update(['stock_quantity' => 37, 'price_minor' => 7777]);
    Promotion::query()->where('code', 'DEMO-V1-WELCOME10')->sole()->update([
        'is_active' => false,
        'starts_at' => now('UTC')->subYears(10),
        'expires_at' => now('UTC')->subYears(9),
    ]);
    $manager = User::query()->where('email', 'demo-products@example.test')->sole();
    $manager->removeRole('product_manager');
    $manager->update(['password' => 'ChangedLocalPass123!']);
    $lina = User::query()->where('email', 'demo-lina@example.test')->sole();

    foreach ($lina->cart->items as $item) {
        app(CartService::class)->removeItem($lina, (string) $item->id);
    }

    $standard = Order::query()->with('user')->where('idempotency_key', 'demo-v1:standard')->sole();
    app(OrderService::class)->cancelOrder($standard->user, (string) $standard->id);
    User::factory()->create(['email' => 'independent-customer@example.test']);
    Product::factory()->create(['sku' => 'INDEPENDENT-CATALOGUE']);
    Promotion::factory()->fixed()->create(['code' => 'INDEPENDENT-PROMOTION']);
    $state = demoSeederDatabaseState();
    $this->travel(200)->days();
    config(['demo.password' => 'AnotherLocalPass123!']);

    $this->seed(DemoDatabaseSeeder::class);

    expect(demoSeederDatabaseState())->toBe($state);
    expect(Hash::check('ChangedLocalPass123!', $manager->fresh()->password))->toBeTrue();
});

it('rejects demo seeding in unauthorized environments even with force', function (string $seeder, string $environment) {
    $state = demoSeederDatabaseState();
    $this->app->detectEnvironment(fn (): string => $environment);

    expect(fn () => $this->artisan('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true])->run())
        ->toThrow(LogicException::class, 'Demo seeding is only allowed in local and testing environments.');

    expect(demoSeederDatabaseState())->toBe($state);
})->with([
    DemoDatabaseSeeder::class,
    DemoUserSeeder::class,
    DemoProductSeeder::class,
    DemoPromotionSeeder::class,
    DemoOrderSeeder::class,
    DemoCartSeeder::class,
])->with(['production', 'staging']);

it('requires configured local credentials through every demo seeder entrypoint', function (string $seeder) {
    config(['demo.password' => null]);
    $state = demoSeederDatabaseState();

    expect(fn () => $this->seed($seeder))->toThrow(LogicException::class,
        'Set DEMO_SEED_PASSWORD to a local-only password of 12 to 72 bytes without null bytes.');

    expect(demoSeederDatabaseState())->toBe($state);
})->with([
    DemoDatabaseSeeder::class,
    DemoUserSeeder::class,
    DemoProductSeeder::class,
    DemoPromotionSeeder::class,
    DemoOrderSeeder::class,
    DemoCartSeeder::class,
]);

it('rejects invalid configured demo passwords before changing any records', function (mixed $password) {
    config(['demo.password' => $password]);
    $state = demoSeederDatabaseState();

    expect(fn () => $this->seed(DemoDatabaseSeeder::class))->toThrow(LogicException::class,
        'Set DEMO_SEED_PASSWORD to a local-only password of 12 to 72 bytes without null bytes.');

    expect(demoSeederDatabaseState())->toBe($state);
})->with([
    'empty' => '',
    'short' => 'Short123!',
    'over bcrypt byte limit' => str_repeat('x', 73),
    'multibyte byte overflow' => str_repeat('é', 37),
    'null byte' => "LocalDemoTest123!\0",
    'non-string' => 123456789012,
]);

it('keeps the default seeder production safe without configured demo credentials', function () {
    config(['demo.password' => null]);
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('roles', 3);
    $this->assertDatabaseCount('permissions', 7);
});

it('rolls back all fixture writes when a demo email belongs to a different account', function () {
    User::factory()->create(['email' => 'demo-admin@example.test', 'name' => 'Existing account']);
    $state = demoSeederDatabaseState();

    expect(fn () => $this->seed(DemoDatabaseSeeder::class))->toThrow(LogicException::class);

    expect(demoSeederDatabaseState())->toBe($state);
});

it('rolls back all fixture writes on a case insensitive demo SKU collision', function () {
    Product::factory()->create(['sku' => 'demo-v1-keyboard', 'name' => 'Existing catalogue item']);
    $state = demoSeederDatabaseState();

    expect(fn () => $this->seed(DemoDatabaseSeeder::class))->toThrow(LogicException::class, 'Demo product SKU collision: DEMO-V1-KEYBOARD');

    expect(demoSeederDatabaseState())->toBe($state);
});

it('uses an existing owned product with a normalized lowercase SKU without replacing its catalogue data', function () {
    $product = Product::factory()->create([
        'name' => 'Wireless Keyboard',
        'sku' => 'DEMO-V1-KEYBOARD',
        'description' => 'Locally edited keyboard description.',
        'price_minor' => 4999,
        'stock_quantity' => 50,
        'created_at' => now('UTC')->subDays(90),
    ]);
    DB::table('products')->where('id', $product->id)->update(['sku' => 'demo-v1-keyboard']);
    $catalogue = $product->fresh()->only(['name', 'sku', 'description', 'price_minor', 'status']);
    $createdAt = $product->fresh()->getRawOriginal('created_at');

    $this->seed(DemoDatabaseSeeder::class);

    $this->assertDatabaseCount('products', 16);
    expect($product->fresh()->only(['name', 'sku', 'description', 'price_minor', 'status']))->toBe($catalogue);
    expect($product->fresh()->getRawOriginal('created_at'))->toBe($createdAt);
    expect($product->fresh()->stock_quantity)->toBe(49);
    $order = Order::query()->where('idempotency_key', 'demo-v1:standard')->sole();
    $snapshot = $order->items()->where('product_id', $product->id)->sole();
    expect($snapshot->product_sku)->toBe('demo-v1-keyboard');
    expect($snapshot->unit_price_minor)->toBe(4999);
    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 1]);
});

it('refuses to consume a preexisting customer basket while rolling back later fixture setup', function () {
    $this->seed(DemoUserSeeder::class);
    $customer = User::query()->where('email', 'demo-alex@example.test')->sole();
    $product = Product::factory()->create(['sku' => 'CUSTOMER-BASKET-PRODUCT']);
    app(CartService::class)->addItem($customer, $product->id, 1);
    $state = demoSeederDatabaseState();

    expect(fn () => $this->seed(DemoDatabaseSeeder::class))->toThrow(LogicException::class);

    expect(demoSeederDatabaseState())->toBe($state);
});

it('rolls back users catalogue orders inventory and events when transactional event persistence fails', function () {
    $event = 'eloquent.creating: '.OrderOutboxEvent::class;
    $state = demoSeederDatabaseState();
    Event::listen($event, function (): void {
        throw new RuntimeException('Injected demo outbox failure.');
    });

    try {
        expect(fn () => $this->seed(DemoDatabaseSeeder::class))
            ->toThrow(RuntimeException::class, 'Injected demo outbox failure.');
    } finally {
        Event::forget($event);
    }

    expect(demoSeederDatabaseState())->toBe($state);
});
