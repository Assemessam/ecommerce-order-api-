<?php

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\CreateProductData;
use App\DTOs\Product\ProductQuery;
use App\DTOs\Product\UpdateProductData;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Product\ProductCatalogueCache;
use App\Services\Product\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;

uses(LazilyRefreshDatabase::class);

it('requests active visibility while preserving every query option', function () {
    $query = new ProductQuery(search: 'lamp', minPrice: 100, maxPrice: 5000, available: true, sort: 'price', direction: 'asc', page: 2, perPage: 20);
    $paginator = new LengthAwarePaginator([], 0, 20, 2);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('paginate')->once()->with($query, ProductStatus::Active)->andReturn($paginator);
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect($service->listProducts($query))->toBe($paginator);
});

it('returns an active product provided by its repository', function () {
    $product = Product::factory()->make(['id' => 42]);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturn($product);
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect($service->getProduct('42'))->toBe($product);
});

it('raises model-not-found when the repository has no matching product', function () {
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturnNull();
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect(fn () => $service->getProduct('42'))->toThrow(ModelNotFoundException::class);
});

it('raises model-not-found when the repository returns an inactive product', function () {
    $product = Product::factory()->inactive()->make(['id' => 42]);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturn($product);
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect(fn () => $service->getProduct('42'))->toThrow(ModelNotFoundException::class);
});

it('rejects invalid IDs before invoking the repository', function (string $id) {
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldNotReceive('findById');
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect(fn () => $service->getProduct($id))->toThrow(ModelNotFoundException::class);
})->with(['0', '-1', 'abc', '9223372036854775808']);

it('creates a product from a typed command while retaining omitted database defaults', function () {
    $actor = User::factory()->productManager()->create();
    $data = CreateProductData::fromArray(['name' => 'Direct Product', 'sku' => 'DIRECT-PRODUCT', 'price_minor' => 100]);

    $product = app(ProductService::class)->createProduct($actor, $data);

    expect($product->status)->toBe(ProductStatus::Active);
    expect($product->stock_quantity)->toBe(0);
    expect($product->description)->toBeNull();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Direct Product', 'sku' => 'DIRECT-PRODUCT', 'price_minor' => 100]);
});

it('interprets the typed stock command alongside supplied editable attributes', function () {
    $actor = User::factory()->productManager()->create();
    $product = Product::factory()->create(['name' => 'Retained Name', 'description' => 'Description', 'price_minor' => 100, 'stock_quantity' => 8]);
    $data = UpdateProductData::fromArray(['description' => null, 'price_minor' => 0, 'status' => 'inactive', 'stock_adjustment' => -2]);

    $updated = app(ProductService::class)->updateProduct($actor, (string) $product->id, $data);

    expect($updated->name)->toBe('Retained Name');
    expect($updated->description)->toBeNull();
    expect($updated->price_minor)->toBe(0);
    expect($updated->status)->toBe(ProductStatus::Inactive);
    expect($updated->stock_quantity)->toBe(6);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Retained Name', 'description' => null, 'price_minor' => 0, 'stock_quantity' => 6, 'status' => 'inactive']);
});

it('returns the locked product for an empty typed patch without a persistence update', function () {
    $actor = User::factory()->productManager()->create();
    $product = Product::factory()->create();
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findByIdForUpdate')->once()->with($product->id)->andReturn($product);
    $repository->shouldNotReceive('update');
    $service = new ProductService($repository, app(ProductCatalogueCache::class));

    expect($service->updateProduct($actor, (string) $product->id, UpdateProductData::fromArray([])))->toBe($product);
});
