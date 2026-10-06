<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

describe('listing', function () {
    it('returns active products publicly including products with zero stock', function () {
        $this->freezeTime();
        $inStock = Product::factory()->create();
        $outOfStock = Product::factory()->outOfStock()->create();
        Product::factory()->inactive()->create();

        $this->getJson('/api/products')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $outOfStock->id)
            ->assertJsonPath('data.1.id', $inStock->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'], 'links' => ['first', 'last', 'prev', 'next']]);
    });

    it('paginates deterministically and preserves validated filters in links', function () {
        $this->freezeTime();
        $products = Product::factory()->count(5)->create(['name' => 'Desk Lamp', 'price_minor' => 2500]);

        $response = $this->getJson('/api/products?search=desk&min_price=2500&sort=price&direction=asc&per_page=2&page=2&ignored=value')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $products[2]->id)
            ->assertJsonPath('data.1.id', $products[3]->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 4);

        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $parameters);
        expect($parameters)->toBe(['search' => 'desk', 'min_price' => '2500', 'sort' => 'price', 'direction' => 'asc', 'per_page' => '2', 'page' => '3']);
    });

    it('enforces the default page size and allows the maximum page size', function () {
        Product::factory()->count(16)->create();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 16);
        $this->getJson('/api/products?per_page=100')->assertOk()->assertJsonCount(16, 'data')->assertJsonPath('meta.per_page', 100);
    });

    it('searches names SKUs and descriptions using case-insensitive literal substrings', function () {
        $nameMatch = Product::factory()->create(['name' => 'Wireless KEYBOARD']);
        $skuMatch = Product::factory()->create(['name' => 'Mouse', 'sku' => 'KEYBOARD-SKU', 'description' => null]);
        $descriptionMatch = Product::factory()->create(['name' => 'Desk Mat', 'description' => 'A keyboard companion']);
        Product::factory()->inactive()->create(['name' => 'Keyboard']);
        Product::factory()->create(['name' => 'Travel Mug', 'description' => null]);

        $this->getJson('/api/products?search=%20keyBOard%20&sort=created_at&direction=asc')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $nameMatch->id)
            ->assertJsonPath('data.1.id', $skuMatch->id)->assertJsonPath('data.2.id', $descriptionMatch->id);
    });

    it('keeps SKU and description search grouped with visibility price and availability filters', function () {
        $skuMatch = Product::factory()->create(['name' => 'Keyboard', 'sku' => 'DESK-KBD', 'price_minor' => 2000]);
        $descriptionMatch = Product::factory()->create(['name' => 'Lamp', 'description' => 'For your desk', 'price_minor' => 2500]);
        Product::factory()->inactive()->create(['sku' => 'DESK-INACTIVE', 'description' => 'Desk accessory', 'price_minor' => 2200]);
        Product::factory()->outOfStock()->create(['sku' => 'DESK-EMPTY', 'price_minor' => 2200]);
        Product::factory()->create(['description' => 'Desk accessory', 'price_minor' => 4000]);
        Product::factory()->create(['sku' => 'DESK-CHEAP', 'price_minor' => 1000]);

        $this->getJson('/api/products?search=desk&min_price=2000&max_price=2500&available=true&sort=price&direction=asc')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $skuMatch->id)->assertJsonPath('data.1.id', $descriptionMatch->id);
    });

    it('ignores empty search strings', function (string $search) {
        Product::factory()->create();

        $this->getJson('/api/products?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonCount(1, 'data');
    })->with(['empty' => '', 'whitespace' => '   ']);

    it('treats search wildcards and SQL fragments as literal text', function (string $search, string $name) {
        $matching = Product::factory()->create(['name' => $name, 'sku' => 'MATCHING-SKU', 'description' => null]);
        Product::factory()->create(['name' => 'Ordinary Product', 'sku' => 'ORDINARY-SKU', 'description' => null]);

        $this->getJson('/api/products?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    })->with([
        'percent' => ['%', '100% Cotton Shirt'],
        'underscore' => ['_', 'Model_One'],
        'backslash' => ['\\', 'Path\\Cable'],
        'SQL fragment' => ["' OR 1=1 --", "Collector ' OR 1=1 -- Edition"],
        'zero string' => ['0', 'Model 0'],
    ]);

    it('filters inclusive minimum and maximum integer minor-unit prices', function (array $filters, array $prices) {
        Product::factory()->count(3)->sequence(['price_minor' => 0], ['price_minor' => 1500], ['price_minor' => 3000])->create();

        $response = $this->getJson('/api/products?'.http_build_query([...$filters, 'sort' => 'price', 'direction' => 'asc']))
            ->assertOk()->assertJsonCount(count($prices), 'data');

        expect(array_column(array_column($response->json('data'), 'price'), 'amount_minor'))->toBe($prices);
    })->with([
        'minimum' => [['min_price' => 1500], [1500, 3000]],
        'maximum' => [['max_price' => 1500], [0, 1500]],
        'equal range' => [['min_price' => 1500, 'max_price' => 1500], [1500]],
        'zero maximum' => [['max_price' => 0], [0]],
    ]);

    it('combines search price availability and sorting filters', function () {
        $first = Product::factory()->create(['name' => 'Desk Lamp A', 'price_minor' => 2000]);
        $second = Product::factory()->create(['name' => 'Desk Lamp B', 'price_minor' => 2500]);
        Product::factory()->outOfStock()->create(['name' => 'Desk Lamp C', 'price_minor' => 2200]);
        Product::factory()->create(['name' => 'Desk Lamp D', 'price_minor' => 4000]);
        Product::factory()->create(['name' => 'Travel Mug', 'price_minor' => 2200]);
        Product::factory()->inactive()->create(['name' => 'Desk Lamp E', 'price_minor' => 2200]);

        $this->getJson('/api/products?search=lamp&min_price=2000&max_price=2500&available=true&sort=price&direction=desc')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id);
    });

    it('filters in-stock or out-of-stock active products', function (string $available, bool $inStock) {
        $stocked = Product::factory()->create();
        $empty = Product::factory()->outOfStock()->create();
        Product::factory()->inactive()->create();
        Product::factory()->inactive()->outOfStock()->create();

        $this->getJson('/api/products?available='.$available)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inStock ? $stocked->id : $empty->id);
    })->with(['true' => ['true', true], 'one' => ['1', true], 'false' => ['false', false], 'zero' => ['0', false]]);

    it('sorts each allow-listed field in both directions', function (string $sort, string $direction, array $names) {
        Product::factory()->count(3)->sequence(
            ['name' => 'Cherry', 'price_minor' => 3000, 'created_at' => '2026-01-03 00:00:00'],
            ['name' => 'Apple', 'price_minor' => 1000, 'created_at' => '2026-01-01 00:00:00'],
            ['name' => 'Banana', 'price_minor' => 2000, 'created_at' => '2026-01-02 00:00:00'],
        )->create();

        $response = $this->getJson('/api/products?sort='.$sort.'&direction='.$direction)->assertOk();

        expect(array_column($response->json('data'), 'name'))->toBe($names);
    })->with([
        'name ascending' => ['name', 'asc', ['Apple', 'Banana', 'Cherry']],
        'name descending' => ['name', 'desc', ['Cherry', 'Banana', 'Apple']],
        'price ascending' => ['price', 'asc', ['Apple', 'Banana', 'Cherry']],
        'price descending' => ['price', 'desc', ['Cherry', 'Banana', 'Apple']],
        'date ascending' => ['created_at', 'asc', ['Apple', 'Banana', 'Cherry']],
        'date descending' => ['created_at', 'desc', ['Cherry', 'Banana', 'Apple']],
    ]);

    it('breaks sort ties by ID in the selected direction', function (string $sort, string $direction) {
        $this->freezeTime();
        $products = Product::factory()->count(3)->create(['name' => 'Same Name', 'price_minor' => 1000]);
        $expected = $direction === 'asc' ? $products->modelKeys() : array_reverse($products->modelKeys());

        $response = $this->getJson('/api/products?sort='.$sort.'&direction='.$direction.'&per_page=1&page=2')->assertOk();

        $response->assertJsonPath('data.0.id', $expected[1])->assertJsonPath('meta.total', 3);
        $first = $this->getJson('/api/products?sort='.$sort.'&direction='.$direction.'&per_page=1')->assertOk();
        $first->assertJsonPath('data.0.id', $expected[0]);
    })->with([['name', 'asc'], ['name', 'desc'], ['price', 'asc'], ['price', 'desc'], ['created_at', 'asc'], ['created_at', 'desc']]);

    it('returns an empty paginated response when filters find no products', function () {
        Product::factory()->create(['name' => 'Keyboard']);

        $this->getJson('/api/products?search=missing')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null)
            ->assertJsonPath('links.prev', null)->assertJsonPath('links.next', null);
    });

    it('returns an empty page beyond the last page while retaining the total', function () {
        Product::factory()->create();

        $this->getJson('/api/products?page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1);
    });

    it('filters large monetary values without floating-point rounding', function () {
        $lower = Product::factory()->create(['price_minor' => 9007199254740992]);
        $higher = Product::factory()->create(['price_minor' => 9007199254740993]);

        $this->getJson('/api/products?min_price=9007199254740993&max_price=9007199254740993')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $higher->id)
            ->assertJsonPath('data.0.price.amount_minor', 9007199254740993);
        $this->assertModelExists($lower);
    });

    it('returns 422 for invalid query fields with the existing error envelope', function (string $field, mixed $value, string $message) {
        $response = $this->get('/api/products?'.http_build_query([$field => $value]))->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'The given data was invalid.')
            ->assertJsonPath('error.details.fields.'.$field.'.0', $message)
            ->assertHeader('X-Request-ID');

        expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-ID'));
    })->with([
        'array search' => ['search', ['lamp'], 'The search field must be a string.'],
        'long search' => ['search', str_repeat('x', 101), 'The search field must not be greater than 100 characters.'],
        'null byte search' => ['search', "lamp\0shade", 'The search must not contain null bytes.'],
        'negative minimum' => ['min_price', -1, 'The min price field must be at least 0.'],
        'negative maximum' => ['max_price', -1, 'The max price field must be at least 0.'],
        'decimal minimum' => ['min_price', '1.25', 'The min price field must be an integer.'],
        'scientific maximum' => ['max_price', '1e3', 'The max price field must be an integer.'],
        'text price' => ['min_price', 'free', 'The min price field must be an integer.'],
        'array price' => ['max_price', [10], 'The max price field must be an integer.'],
        'overflow price' => ['min_price', '9223372036854775808', 'The min price field must be an integer.'],
        'empty minimum' => ['min_price', '', 'The min price field is required.'],
        'empty maximum' => ['max_price', '', 'The max price field is required.'],
        'invalid availability' => ['available', 'yes', 'The available field must be true or false.'],
        'array availability' => ['available', [1], 'The available field must be true or false.'],
        'empty availability' => ['available', '', 'The available field is required.'],
        'unsupported sort' => ['sort', 'sku', 'The selected sort is invalid.'],
        'sort injection' => ['sort', 'price desc; DROP TABLE products', 'The selected sort is invalid.'],
        'array sort' => ['sort', ['name'], 'The selected sort is invalid.'],
        'empty sort' => ['sort', '', 'The sort field is required.'],
        'unsupported direction' => ['direction', 'sideways', 'The selected direction is invalid.'],
        'direction injection' => ['direction', 'asc; DROP TABLE products', 'The selected direction is invalid.'],
        'empty direction' => ['direction', '', 'The direction field is required.'],
        'zero page' => ['page', 0, 'The page field must be at least 1.'],
        'negative page' => ['page', -1, 'The page field must be at least 1.'],
        'decimal page' => ['page', '1.5', 'The page field must be an integer.'],
        'array page' => ['page', [1], 'The page field must be an integer.'],
        'oversized page' => ['page', '2147483648', 'The page field must not be greater than 2147483647.'],
        'empty page' => ['page', '', 'The page field is required.'],
        'zero page size' => ['per_page', 0, 'The per page field must be at least 1.'],
        'negative page size' => ['per_page', -1, 'The per page field must be at least 1.'],
        'decimal page size' => ['per_page', '2.5', 'The per page field must be an integer.'],
        'oversized page size' => ['per_page', 101, 'The per page field must not be greater than 100.'],
        'empty page size' => ['per_page', '', 'The per page field is required.'],
    ]);

    it('returns 422 when the minimum price exceeds the maximum price', function () {
        $this->getJson('/api/products?min_price=2000&max_price=1000')->assertUnprocessable()
            ->assertJsonPath('error.details.fields.max_price', ['The maximum price must be greater than or equal to the minimum price.']);
    });

    it('compares adjacent large price boundaries exactly', function () {
        $this->getJson('/api/products?min_price=9007199254740993&max_price=9007199254740992')->assertUnprocessable()
            ->assertJsonPath('error.details.fields.max_price', ['The maximum price must be greater than or equal to the minimum price.']);
    });

    it('ignores unknown query fields without allowing inactive visibility', function () {
        Product::factory()->create();
        Product::factory()->inactive()->create();

        $this->getJson('/api/products?status=inactive&include_inactive=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'active');
    });

    it('validates query parameters even when a GET body contains different values', function () {
        $this->json('GET', '/api/products?sort=sku', ['sort' => 'name'])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.sort', ['The selected sort is invalid.']);
    });
});

describe('details', function () {
    it('returns exactly the public fields of an active product without authentication', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $product = Product::factory()->outOfStock()->create([
            'name' => 'Travel Mug', 'sku' => ' mug-001 ', 'description' => null, 'price_minor' => 1899,
        ]);

        $this->getJson('/api/products/'.$product->id)->assertOk()->assertExactJson(['data' => [
            'id' => $product->id,
            'name' => 'Travel Mug',
            'sku' => 'MUG-001',
            'description' => null,
            'price' => ['amount_minor' => 1899, 'currency' => 'USD'],
            'stock_quantity' => 0,
            'status' => 'active',
            'created_at' => '2026-10-06T12:00:00.000000Z',
            'updated_at' => '2026-10-06T12:00:00.000000Z',
        ]]);
    });

    it('returns 404 for missing malformed or overflowing product IDs', function (string $id) {
        $this->get('/api/products/'.$id)->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('error.message', 'The requested resource was not found.');
    })->with(['missing' => '999999', 'zero' => '0', 'negative' => '-1', 'text' => 'abc', 'overflow' => '9223372036854775808']);

    it('returns 404 for an inactive product even with stock', function () {
        $product = Product::factory()->inactive()->create();

        $this->getJson('/api/products/'.$product->id)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    });

    it('uses the configured deployment currency in list and detail resources', function () {
        config(['catalogue.currency' => 'EUR']);
        $product = Product::factory()->create(['price_minor' => 2500]);

        $this->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.price', ['amount_minor' => 2500, 'currency' => 'EUR']);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.price', ['amount_minor' => 2500, 'currency' => 'EUR']);
    });
});
