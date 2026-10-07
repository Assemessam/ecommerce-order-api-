<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

describe('creation', function () {
    it('creates normalized products with exact minor-unit prices and ignores forged identifiers', function (int $price) {
        $administrator = User::factory()->administrator()->create();

        $response = $this->withToken($administrator->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/products', [
                'name' => ' Desk Lamp ', 'sku' => ' lamp-01 ', 'description' => 'Reading lamp',
                'price_minor' => $price, 'stock_quantity' => 7, 'status' => 'inactive',
                'id' => 9000, 'is_admin' => true, 'created_at' => '2000-01-01',
            ])->assertCreated()->assertJsonPath('data.name', 'Desk Lamp')
            ->assertJsonPath('data.sku', 'LAMP-01')->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.price', ['amount_minor' => $price, 'currency' => 'USD'])
            ->assertJsonPath('data.stock_quantity', 7);

        $this->assertDatabaseHas('products', ['id' => $response->json('data.id'), 'sku' => 'LAMP-01', 'price_minor' => $price, 'stock_quantity' => 7]);
        $this->assertDatabaseMissing('products', ['id' => 9000]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('orders', 0);
    })->with(['free' => 0, 'ordinary' => 1899, 'bigint maximum' => PHP_INT_MAX]);

    it('uses database defaults when optional creation fields are absent', function () {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/products', ['name' => 'Product', 'sku' => 'DEFAULT', 'price_minor' => 100])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.stock_quantity', 0)
            ->assertJsonPath('data.description', null);
    });

    it('returns 422 for invalid product fields without creating a product', function (string $field, mixed $value) {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/products', array_replace(['name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100], [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        $this->assertDatabaseCount('products', 0);
    })->with([
        'blank name' => ['name', '   '], 'long name' => ['name', str_repeat('a', 256)],
        'null byte name' => ['name', "bad\0name"], 'blank SKU' => ['sku', ' '],
        'long SKU' => ['sku', str_repeat('A', 256)], 'null byte SKU' => ['sku', "BAD\0SKU"],
        'negative price' => ['price_minor', -1], 'decimal price' => ['price_minor', 1.5],
        'string price' => ['price_minor', '100'], 'null price' => ['price_minor', null],
        'overflow price' => ['price_minor', 9223372036854775808.0],
        'negative inventory' => ['stock_quantity', -1], 'float inventory' => ['stock_quantity', 2.5],
        'string inventory' => ['stock_quantity', '2'], 'overflow inventory' => ['stock_quantity', 9223372036854775808.0],
        'unknown status' => ['status', 'deleted'], 'null status' => ['status', null],
        'long description' => ['description', str_repeat('a', 10001)],
        'null byte description' => ['description', "bad\0text"], 'array description' => ['description', []],
        'creation adjustment' => ['stock_adjustment', 5],
    ]);

    it('returns 422 for a duplicate normalized SKU including legacy lowercase rows', function () {
        $existing = Product::factory()->create(['sku' => 'EXISTING']);
        DB::table('products')->where('id', $existing->id)->update(['sku' => 'existing']);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->postJson('/api/admin/products', ['name' => 'Duplicate', 'sku' => ' existing ', 'price_minor' => 0])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.sku.0', 'The SKU has already been taken.');

        $this->assertDatabaseCount('products', 1);
        expect($existing->fresh()->sku)->toBe('existing');

    });
});

describe('listing and detail', function () {
    it('shows inactive products to administrators with bounded filters and stable pagination', function () {
        $this->freezeTime();
        $active = Product::factory()->create(['name' => 'Desk Active']);
        $inactive = Product::factory()->inactive()->create(['name' => 'Desk Inactive']);
        $token = User::factory()->administrator()->create()->createToken('admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/products?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/admin/products?status=inactive&search=desk&ignored=secret')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/admin/products/'.$inactive->id)->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/products/'.$inactive->id)->assertNotFound();
        $this->getJson('/api/admin/products?status=deleted')->assertUnprocessable();
        $this->getJson('/api/admin/products?sort=id;DROP TABLE products')->assertUnprocessable();
        $this->getJson('/api/admin/products?per_page=101')->assertUnprocessable();
    });

    it('returns 404 for missing zero and overflowing identifiers', function (string $id) {
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->getJson('/api/admin/products/'.$id)->assertNotFound();
    })->with(['999', '0', '9223372036854775808']);
});

describe('updates', function () {
    it('preserves omitted fields while accepting zero price and an explicit description clear', function (array $patch, ?string $description) {
        $product = Product::factory()->create([
            'name' => 'Retained Name', 'sku' => 'RETAINED-SKU', 'description' => 'Retained Description',
            'price_minor' => 100, 'stock_quantity' => 5,
        ]);

        $this->withToken(User::factory()->productManager()->create()->createToken('products')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, $patch)
            ->assertOk()->assertJsonPath('data.description', $description)->assertJsonPath('data.price.amount_minor', 0)
            ->assertJsonPath('data.name', 'Retained Name')->assertJsonPath('data.sku', 'RETAINED-SKU')
            ->assertJsonPath('data.stock_quantity', 5)->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('products', [
            'id' => $product->id, 'name' => 'Retained Name', 'sku' => 'RETAINED-SKU',
            'description' => $description, 'price_minor' => 0, 'stock_quantity' => 5, 'status' => 'active',
        ]);
    })->with([
        'omitted description' => [['price_minor' => 0], 'Retained Description'],
        'explicit null description' => [['price_minor' => 0, 'description' => null], null],
    ]);

    it('accepts an empty product patch without changing saved attributes', function () {
        $product = Product::factory()->create(['description' => 'Retained Description']);
        $original = $product->refresh()->getAttributes();

        $this->withToken(User::factory()->productManager()->create()->createToken('products')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, [])
            ->assertOk()->assertJsonPath('data.description', 'Retained Description');

        expect($product->fresh()->getAttributes())->toBe($original);
    });

    it('updates product properties and adjusts stock while leaving purchase snapshots unchanged', function () {
        $product = Product::factory()->create(['name' => 'Original', 'sku' => 'ORIGINAL', 'price_minor' => 1200, 'stock_quantity' => 10]);
        $item = CartItem::factory()->for($product)->create(['quantity' => 2]);
        $this->withToken($item->cart->user->createToken('customer')->plainTextToken)->postJson('/api/checkout')->assertCreated();
        $order = Order::query()->with('items')->sole();
        $snapshot = $order->items->sole()->getAttributes();
        $this->app['auth']->forgetGuards();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, [
                'name' => 'Updated', 'sku' => ' updated ', 'description' => null,
                'price_minor' => 2400, 'status' => 'inactive', 'stock_adjustment' => -3,
                'id' => 9000, 'unit_price_minor' => 1,
            ])->assertOk()->assertJsonPath('data.sku', 'UPDATED')->assertJsonPath('data.price.amount_minor', 2400)
            ->assertJsonPath('data.stock_quantity', 5)->assertJsonPath('data.status', 'inactive');

        expect($order->items->sole()->fresh()->getAttributes())->toBe($snapshot);
        expect($order->fresh()->subtotal_minor)->toBe(2400);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Updated', 'price_minor' => 2400, 'stock_quantity' => 5]);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->patchJson('/api/admin/products/'.$product->id, ['status' => 'active'])->assertOk();
        $this->getJson('/api/products/'.$product->id)->assertOk();
    });

    it('returns 422 for duplicate SKU updates and permits keeping the same normalized SKU', function () {
        Product::factory()->create(['sku' => 'OTHER']);
        $product = Product::factory()->create(['sku' => 'ORIGINAL']);
        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken);

        $this->patchJson('/api/admin/products/'.$product->id, ['sku' => ' other ', 'name' => 'Rejected'])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.sku.0', 'The SKU has already been taken.');
        expect($product->fresh()->sku)->toBe('ORIGINAL');
        expect($product->fresh()->name)->toBe($product->name);
        $this->patchJson('/api/admin/products/'.$product->id, ['sku' => ' original '])->assertOk();
    });

    it('returns 422 for absolute or invalid stock updates without changing the product', function (string $field, mixed $value) {
        $product = Product::factory()->create(['stock_quantity' => 5]);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, [$field => $value])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        expect($product->fresh()->stock_quantity)->toBe(5);
    })->with([
        'absolute stock' => ['stock_quantity', 20], 'null absolute stock' => ['stock_quantity', null],
        'zero adjustment' => ['stock_adjustment', 0], 'null adjustment' => ['stock_adjustment', null],
        'string adjustment' => ['stock_adjustment', '2'], 'float adjustment' => ['stock_adjustment', 2.5],
        'minimum bigint' => ['stock_adjustment', PHP_INT_MIN],
        'overflow adjustment' => ['stock_adjustment', 9223372036854775808.0],
    ]);

    it('returns 409 and rolls back every field when an adjustment would underflow or overflow', function (int $stock, int $adjustment) {
        $product = Product::factory()->create(['name' => 'Original', 'stock_quantity' => $stock]);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => $adjustment, 'name' => 'Rejected'])
            ->assertConflict()->assertJsonPath('error.code', 'INVENTORY_ADJUSTMENT_CONFLICT');

        expect($product->fresh()->stock_quantity)->toBe($stock);
        expect($product->fresh()->name)->toBe('Original');
    })->with(['underflow' => [5, -6], 'overflow' => [PHP_INT_MAX, 1]]);

    it('allows adjustments exactly to zero and the maximum bigint', function (int $stock, int $adjustment, int $expected) {
        $product = Product::factory()->create(['stock_quantity' => $stock]);

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => $adjustment])
            ->assertOk()->assertJsonPath('data.stock_quantity', $expected);

        expect($product->fresh()->stock_quantity)->toBe($expected);
    })->with(['to zero' => [5, -5, 0], 'to maximum' => [1, PHP_INT_MAX - 1, PHP_INT_MAX]]);

    it('provides no product hard-delete endpoint', function () {
        $product = Product::factory()->create();

        $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
            ->deleteJson('/api/admin/products/'.$product->id)->assertMethodNotAllowed();

        $this->assertModelExists($product);
    });
});

it('returns 422 for whole-number JSON floats instead of coercing them to minor units', function () {
    $token = User::factory()->administrator()->create()->createToken('admin')->plainTextToken;

    $this->call('POST', '/api/admin/products', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], content: '{"name":"Product","sku":"FLOAT","price_minor":2.0}')
        ->assertUnprocessable()->assertJsonPath('error.details.fields.price_minor.0', 'The price minor field must be an integer.');

    $this->assertDatabaseCount('products', 0);
});

it('returns 422 for missing required creation fields', function () {
    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->postJson('/api/admin/products', [])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'sku', 'price_minor']]]]);

    $this->assertDatabaseCount('products', 0);
});

it('returns 422 for invalid property patches without changing saved values', function (array $data, string $field) {
    $product = Product::factory()->create(['price_minor' => 100]);
    $original = $product->refresh()->getAttributes();

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/products/'.$product->id, $data)->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

    expect($product->fresh()->getAttributes())->toBe($original);
})->with([
    'price' => [['price_minor' => -1], 'price_minor'],
    'required name' => [['name' => null], 'name'],
    'status' => [['status' => 'deleted'], 'status'],
]);

it('rolls back product edits on unexpected persistence failure and returns a sanitized 500', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $original = $product->refresh()->getAttributes();
    Event::listen('eloquent.updated: '.Product::class, function (): void {
        throw new RuntimeException('Private SQL and token details');
    });

    $response = $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/products/'.$product->id, ['name' => 'Rejected', 'stock_adjustment' => 2])
        ->assertInternalServerError()->assertJsonPath('error.code', 'INTERNAL_ERROR');

    expect($response->getContent())->not->toContain('Private SQL', 'token details');
    expect($product->fresh()->getAttributes())->toBe($original);
});
