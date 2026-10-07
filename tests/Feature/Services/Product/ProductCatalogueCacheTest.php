<?php

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\CreateProductData;
use App\DTOs\Product\ProductQuery;
use App\DTOs\Product\UpdateProductData;
use App\Enums\ProductStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use App\Services\Product\ProductCatalogueCache;
use App\Services\Product\ProductService;
use Database\Seeders\ProductSeeder;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config([
        'catalogue.cache.enabled' => true,
        'catalogue.cache.store' => 'catalogue',
        'catalogue.cache.namespace' => 'ecommerce:catalogue:testing:'.Str::uuid(),
    ]);
    expect((int) config('database.redis.catalogue.database'))->toBe(3);
    expect(Redis::connection('catalogue')->ping())->toBeTrue();
});

afterEach(function () {
    $connection = Redis::connection('catalogue');
    $prefix = $connection->client()->getOption(\Redis::OPT_PREFIX);
    $pattern = Cache::store('catalogue')->getStore()->getPrefix().config('catalogue.cache.namespace').':*';

    foreach ($connection->keys($pattern) as $key) {
        $connection->del(substr($key, strlen($prefix)));
    }

    expect($connection->keys($pattern))->toBe([]);
    DB::statement('TRUNCATE orders CASCADE');

    Cache::store('file')->forget('catalogue-cache-warning:'.hash('sha256', config('catalogue.cache.namespace').':v1:public:generation'));
});

function catalogueDatabaseReads(): int
{
    return collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], '"products"'))->count();
}

function catalogueGeneration(): ?string
{
    return Cache::store('catalogue')->get(config('catalogue.cache.namespace').':v1:public:generation');
}

function catalogueUnavailableConnection(): void
{
    config([
        'database.redis.catalogue_unavailable' => [
            'host' => '127.0.0.1', 'port' => 1, 'database' => 3,
            'timeout' => 0.05, 'read_timeout' => 0.05, 'max_retries' => 0,
        ],
        'cache.stores.catalogue_unavailable' => [
            'driver' => 'redis', 'connection' => 'catalogue_unavailable',
        ],
        'catalogue.cache.store' => 'catalogue_unavailable',
    ]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
}

it('reads PostgreSQL on a miss and serves an identical scalar response from real Redis', function () {
    Product::factory()->create();
    Product::factory()->inactive()->create();
    DB::enableQueryLog();

    $first = $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    expect(catalogueDatabaseReads())->toBe(2);
    DB::flushQueryLog();
    $second = $this->getJson('/api/products')->assertOk()->assertExactJson($first->json());

    expect(catalogueDatabaseReads())->toBe(0);
    $second->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Request-ID');
});

it('keeps each filter page and sort in a separate cache entry', function (string $query) {
    Product::factory()->count(3)->sequence(
        ['name' => 'Alpha', 'price_minor' => 100, 'stock_quantity' => 0],
        ['name' => 'Bravo', 'price_minor' => 200, 'stock_quantity' => 2],
        ['name' => 'Charlie', 'price_minor' => 300, 'stock_quantity' => 3],
    )->create();
    $this->getJson('/api/products')->assertOk();
    DB::enableQueryLog();

    $first = $this->getJson('/api/products?'.$query)->assertOk();
    expect(catalogueDatabaseReads())->toBe(2);
    DB::flushQueryLog();
    $this->getJson('/api/products?'.$query)->assertOk()->assertExactJson($first->json());

    expect(catalogueDatabaseReads())->toBe(0);
})->with([
    'search' => 'search=Alpha', 'minimum' => 'min_price=200', 'maximum' => 'max_price=200',
    'available' => 'available=true', 'unavailable' => 'available=false', 'page' => 'page=2',
    'page size' => 'per_page=1', 'name sort' => 'sort=name', 'price sort' => 'sort=price',
    'ascending' => 'direction=asc',
]);

it('reuses normalized parameters while retaining each requests validated links', function (string $first, string $second) {
    Product::factory()->count(2)->create(['name' => 'Desk Lamp', 'stock_quantity' => 2]);
    $response = $this->getJson('/api/products?'.$first)->assertOk();
    DB::enableQueryLog();

    $cached = $this->getJson('/api/products?'.$second)->assertOk();

    expect(catalogueDatabaseReads())->toBe(0);
    expect($cached->json('data'))->toBe($response->json('data'));
    expect($cached->json('links.first'))->not->toContain('ignored');
})->with([
    'booleans and integers' => ['available=true&page=1&per_page=15', 'per_page=15&available=1&page=1&ignored=x'],
    'defaults' => ['', 'sort=created_at&direction=desc&page=1&per_page=15'],
    'trimmed search' => ['search=%20Desk%20', 'search=Desk'],
    'empty search' => ['search=%20%20', ''],
]);

it('validates before cache access even with a warm listing', function () {
    $this->getJson('/api/products')->assertOk();
    catalogueUnavailableConnection();

    $this->getJson('/api/products?sort=sku')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(catalogueGeneration())->not->toBeNull();
});

it('rebuilds pagination metadata and host specific links on a hit', function () {
    $products = Product::factory()->count(5)->create(['name' => 'Desk Lamp']);
    $url = '/api/products?search=Desk&sort=name&direction=asc&per_page=2&page=2';
    $first = $this->getJson($url)->assertOk();
    DB::enableQueryLog();

    $cached = $this->getJson('http://catalogue.example.test'.$url)->assertOk()
        ->assertJsonPath('data.0.id', $products[2]->id)->assertJsonPath('data.1.id', $products[3]->id)
        ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.from', 3)->assertJsonPath('meta.to', 4)
        ->assertJsonPath('meta.path', 'http://catalogue.example.test/api/products');

    expect(catalogueDatabaseReads())->toBe(0);
    expect($cached->json('data'))->toBe($first->json('data'));
    expect($cached->json('links.next'))->toStartWith('http://catalogue.example.test/api/products?');
});

it('keeps detail and admin reads authoritative and separate from a warm public entry', function () {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Before']);
    Product::factory()->inactive()->create();
    $this->getJson('/api/products')->assertOk();
    $product->update(['name' => 'After']);

    $this->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.name', 'After');
    $this->withToken($admin->createToken('admin')->plainTextToken)->getJson('/api/admin/products')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Before');
    config(['catalogue.currency' => 'EUR']);
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.price.currency', 'EUR');
});

it('refreshes existing empty listings after committed product creation', function () {
    $admin = User::factory()->administrator()->create();
    $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    $generation = catalogueGeneration();

    $product = app(ProductService::class)->createProduct($admin, CreateProductData::fromArray(['name' => 'Lamp', 'sku' => 'LAMP', 'price_minor' => 100]));

    expect(catalogueGeneration())->not->toBe($generation);
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.id', $product->id)->assertJsonPath('meta.total', 1);
});

it('refreshes every catalogue visible edit after commit', function (array $edit, string $path, mixed $expected) {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Original', 'sku' => 'ORIGINAL', 'description' => 'Original', 'price_minor' => 100, 'stock_quantity' => 2]);
    $this->getJson('/api/products')->assertOk();
    $generation = catalogueGeneration();

    app(ProductService::class)->updateProduct($admin, (string) $product->id, UpdateProductData::fromArray($edit));

    expect(catalogueGeneration())->not->toBe($generation);
    $this->getJson('/api/products')->assertOk()->assertJsonPath($path, $expected);
})->with([
    'name' => [['name' => 'Changed'], 'data.0.name', 'Changed'],
    'SKU' => [['sku' => 'CHANGED'], 'data.0.sku', 'CHANGED'],
    'description' => [['description' => 'Changed'], 'data.0.description', 'Changed'],
    'null description' => [['description' => null], 'data.0.description', null],
    'price' => [['price_minor' => 300], 'data.0.price.amount_minor', 300],
    'status' => [['status' => 'inactive'], 'meta.total', 0],
    'stock' => [['stock_adjustment' => -2], 'data.0.stock_quantity', 0],
]);

it('refreshes search price and availability membership and total counts after edits', function () {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Original', 'sku' => 'ORIGINAL', 'description' => null, 'price_minor' => 100, 'stock_quantity' => 2]);
    $urls = ['/api/products?search=changed', '/api/products?min_price=300', '/api/products?available=false'];
    foreach ($urls as $url) {
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
    }

    app(ProductService::class)->updateProduct($admin, (string) $product->id, UpdateProductData::fromArray(['sku' => 'CHANGED', 'price_minor' => 300, 'stock_adjustment' => -2]));

    foreach ($urls as $url) {
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);
    }
});

it('does not invalidate an empty admin patch or an idempotent seeder rerun', function () {
    $admin = User::factory()->administrator()->create();
    $this->seed(ProductSeeder::class);
    $this->getJson('/api/products')->assertOk();
    $generation = catalogueGeneration();

    app(ProductService::class)->updateProduct($admin, (string) Product::firstOrFail()->id, UpdateProductData::fromArray([]));
    $this->seed(ProductSeeder::class);

    expect(catalogueGeneration())->toBe($generation);
});

it('refreshes committed sample product insertions', function () {
    $this->getJson('/api/products')->assertOk()->assertJsonPath('meta.total', 0);

    $this->seed(ProductSeeder::class);

    $this->getJson('/api/products')->assertOk()->assertJsonPath('meta.total', 4);
});

it('invalidates checkout deduction and cancellation restoration once without changing replay behavior', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 2]))->create(['quantity' => 2]);
    $user = $item->cart->user;
    $this->getJson('/api/products?available=true')->assertOk()->assertJsonPath('meta.total', 1);
    $generation = catalogueGeneration();

    $purchase = app(CheckoutService::class)->checkout($user, 'catalogue-purchase');

    expect(catalogueGeneration())->not->toBe($generation);
    $this->getJson('/api/products?available=true')->assertOk()->assertJsonPath('meta.total', 0);
    $generation = catalogueGeneration();
    expect(app(CheckoutService::class)->checkout($user, 'catalogue-purchase')->isReplay)->toBeTrue();
    expect(catalogueGeneration())->toBe($generation);
    app(OrderService::class)->cancelOrder($user, (string) $purchase->order->id);
    $this->getJson('/api/products?available=true')->assertOk()->assertJsonPath('data.0.stock_quantity', 2);
    $generation = catalogueGeneration();
    app(OrderService::class)->cancelOrder($user, (string) $purchase->order->id);
    expect(catalogueGeneration())->toBe($generation);
});

it('leaves the committed cache untouched after outer rollback of a nested mutation', function (string $operation) {
    $admin = User::factory()->administrator()->create();
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $order = $operation === 'cancellation' ? app(CheckoutService::class)->checkout($item->cart->user)->order : null;
    $first = $this->getJson('/api/products')->assertOk();
    $generation = catalogueGeneration();
    DB::beginTransaction();

    try {
        match ($operation) {
            'create' => app(ProductService::class)->createProduct($admin, CreateProductData::fromArray(['name' => 'New', 'sku' => 'NEW', 'price_minor' => 10])),
            'update' => app(ProductService::class)->updateProduct($admin, (string) $item->product_id, UpdateProductData::fromArray(['name' => 'Changed', 'stock_adjustment' => 1])),
            'checkout' => app(CheckoutService::class)->checkout($item->cart->user),
            'cancellation' => app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id),
        };
        expect(catalogueGeneration())->toBe($generation);
    } finally {
        DB::rollBack();
    }

    $this->getJson('/api/products')->assertOk()->assertExactJson($first->json());
    expect(catalogueGeneration())->toBe($generation);
})->with(['create', 'update', 'checkout', 'cancellation']);

it('bypasses cache inside transactions without publishing uncommitted rows', function () {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Committed']);
    $this->getJson('/api/products')->assertOk();
    DB::beginTransaction();

    try {
        app(ProductService::class)->updateProduct($admin, (string) $product->id, UpdateProductData::fromArray(['name' => 'Uncommitted']));
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'Uncommitted');
    } finally {
        DB::rollBack();
    }

    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'Committed');
});

it('prevents an older in flight database read from repopulating the current generation', function () {
    $admin = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Before']);
    $repository = new EloquentProductRepository;
    $cache = app(ProductCatalogueCache::class);

    $stale = $cache->paginate(new ProductQuery, function () use ($repository, $admin, $product) {
        $oldPage = $repository->paginate(new ProductQuery, ProductStatus::Active);
        app(ProductService::class)->updateProduct($admin, (string) $product->id, UpdateProductData::fromArray(['name' => 'After']));

        return $oldPage;
    });

    expect($stale->items()[0]->name)->toBe('Before');
    DB::enableQueryLog();
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'After');
    expect(catalogueDatabaseReads())->toBe(2);
    DB::flushQueryLog();
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'After');
    expect(catalogueDatabaseReads())->toBe(0);
});

it('cannot resurrect an old namespace if the generation marker is evicted', function () {
    $product = Product::factory()->create(['name' => 'Before']);
    $this->getJson('/api/products')->assertOk();
    $oldGeneration = catalogueGeneration();
    Cache::store('catalogue')->forget(config('catalogue.cache.namespace').':v1:public:generation');
    $product->update(['name' => 'After']);

    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'After');

    expect(catalogueGeneration())->not->toBe($oldGeneration);
});

it('queries PostgreSQL after an entry expires in real Redis', function () {
    config(['catalogue.cache.ttl' => 2]);
    $product = Product::factory()->create(['name' => 'Before']);
    $this->getJson('/api/products')->assertOk();
    $product->update(['name' => 'After']);
    /** Real Redis owns expiration; advancing PHP fake time cannot expire a Redis key. */
    usleep(2_100_000);
    DB::enableQueryLog();

    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.name', 'After');

    expect(catalogueDatabaseReads())->toBe(2);
});

it('falls back to PostgreSQL for unreachable Redis or invalid cache configuration', function (string $failure) {
    Product::factory()->create();
    match ($failure) {
        'unavailable' => catalogueUnavailableConnection(),
        'invalid connection' => config(['cache.stores.catalogue.connection' => 'does_not_exist']),
        'invalid store' => config(['catalogue.cache.store' => 'does_not_exist']),
    };
    DB::enableQueryLog();

    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');

    expect(catalogueDatabaseReads())->toBe(4);
})->with(['unavailable', 'invalid connection', 'invalid store']);

it('does not hide repository exceptions behind the cache failure boundary', function () {
    catalogueUnavailableConnection();
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('paginate')->once()->andThrow(new RuntimeException('Database application error'));
    $this->app->instance(ProductRepositoryInterface::class, $repository);

    expect(fn () => app(ProductService::class)->listProducts(new ProductQuery))->toThrow(RuntimeException::class, 'Database application error');
});

it('returns committed checkout and replay results when invalidation fails after commit', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 3, 'price_minor' => 100]))->create(['quantity' => 2]);
    $token = $item->cart->user->createToken('checkout')->plainTextToken;
    $this->getJson('/api/products')->assertOk();
    $generation = catalogueGeneration();
    Event::listen('eloquent.created: '.Order::class, function () {
        DB::afterCommit(function () {
            expect(DB::transactionLevel())->toBe(0);
            catalogueUnavailableConnection();
        });
    });

    $orderId = $this->withToken($token)->withHeader('Idempotency-Key', 'redis-down')->postJson('/api/checkout')
        ->assertCreated()->json('data.id');

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('cart_items', 0);
    expect($item->product->fresh()->stock_quantity)->toBe(1);
    expect(catalogueGeneration())->toBe($generation);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/checkout')->assertOk()->assertJsonPath('data.id', $orderId);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/orders/'.$orderId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($item->product->fresh()->stock_quantity)->toBe(3);
});

it('uses current PostgreSQL price stock and status even when Redis holds stale catalogue data', function (array $change, int $status, ?int $price) {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 3, 'price_minor' => 100]))->create(['quantity' => 2]);
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.price.amount_minor', 100);
    $item->product->update($change);
    $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.stock_quantity', 3);

    $response = $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')->assertStatus($status);

    if ($price !== null) {
        $response->assertJsonPath('data.items.0.unit_price_minor', $price);
        $this->assertDatabaseCount('orders', 1);
    } else {
        $this->assertDatabaseCount('orders', 0);
        $this->assertModelExists($item);
    }
})->with([
    'current price' => [['price_minor' => 500], 201, 500],
    'insufficient stock' => [['stock_quantity' => 1], 409, null],
    'inactive status' => [['status' => 'inactive'], 409, null],
]);

it('logs cache failures at most once per minute per namespace without connection internals', function () {
    Product::factory()->create();
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with(
        'Product catalogue cache unavailable; using PostgreSQL.',
        ['operation' => 'generation', 'store' => 'catalogue_unavailable', 'exception_class' => RedisException::class],
    );
    $this->app->instance(LoggerInterface::class, $logger);
    $this->app->instance(ProductCatalogueCache::class, new ProductCatalogueCache(app(Factory::class), $logger));
    catalogueUnavailableConnection();

    $this->getJson('/api/products')->assertOk();
    $this->app->forgetInstance(ProductCatalogueCache::class);
    $this->getJson('/api/products')->assertOk();
});

it('still returns database data when Redis fails during cache population', function () {
    Product::factory()->create();
    $repository = new EloquentProductRepository;

    $result = app(ProductCatalogueCache::class)->paginate(new ProductQuery, function () use ($repository) {
        $page = $repository->paginate(new ProductQuery, ProductStatus::Active);
        catalogueUnavailableConnection();

        return $page;
    });

    expect($result->total())->toBe(1);
    expect($result->items())->toHaveCount(1);
});

it('keeps unrelated Redis keys intact during cache invalidation and test cleanup', function () {
    $sentinel = 'catalogue-unrelated-sentinel:'.Str::uuid();
    Cache::store('catalogue')->put($sentinel, 'unrelated', 60);
    $this->getJson('/api/products')->assertOk();

    try {
        app(ProductCatalogueCache::class)->invalidateAfterCommit();

        expect(Cache::store('catalogue')->get($sentinel))->toBe('unrelated');
    } finally {
        Cache::store('catalogue')->forget($sentinel);
    }
});

it('serves representative cached pages without product queries and reports local timings', function () {
    Product::factory()->count(200)->sequence(
        ['name' => 'Benchmark Lamp', 'price_minor' => 100, 'stock_quantity' => 5],
        ['name' => 'Benchmark Keyboard', 'price_minor' => 1000, 'stock_quantity' => 0],
    )->create();
    $results = [];
    $measure = function (string $url): float {
        $start = hrtime(true);
        $this->getJson($url)->assertOk();

        return (hrtime(true) - $start) / 1_000_000;
    };

    foreach ([
        'default' => '/api/products',
        'filtered' => '/api/products?search=Lamp&min_price=50&max_price=500&available=true&sort=price&direction=asc',
        'paginated' => '/api/products?per_page=20&page=4&sort=name&direction=asc',
    ] as $name => $url) {
        $this->advanceRateLimitWindows();
        config(['catalogue.cache.enabled' => false]);
        $uncached = [];
        for ($sample = 0; $sample < 25; $sample++) {
            $uncached[] = $measure($url);
        }
        config(['catalogue.cache.enabled' => true]);
        app(ProductCatalogueCache::class)->invalidateAfterCommit();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $cold = $measure($url);
        expect(catalogueDatabaseReads())->toBe(2);
        DB::flushQueryLog();
        $warm = [];
        for ($sample = 0; $sample < 25; $sample++) {
            $warm[] = $measure($url);
        }
        expect(catalogueDatabaseReads())->toBe(0);
        DB::disableQueryLog();
        sort($uncached);
        sort($warm);
        $results[$name] = [
            'cold_ms' => round($cold, 3),
            'uncached_median_ms' => round($uncached[12], 3),
            'cached_median_ms' => round($warm[12], 3),
            'samples_each' => 25,
            'cold_product_queries' => 2,
            'cached_product_queries' => 0,
        ];
    }

    fwrite(STDOUT, "\nCatalogue local HTTP-kernel benchmark (200 products): ".json_encode($results, JSON_THROW_ON_ERROR)."\n");
})->group('catalogue-benchmark');
