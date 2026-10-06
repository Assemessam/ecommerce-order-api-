<?php

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Product\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

it('requests active visibility while preserving every query option', function () {
    $query = new ProductQuery(search: 'lamp', minPrice: 100, maxPrice: 5000, available: true, sort: 'price', direction: 'asc', page: 2, perPage: 20);
    $paginator = new LengthAwarePaginator([], 0, 20, 2);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('paginate')->once()->with($query, ProductStatus::Active)->andReturn($paginator);
    $service = new ProductService($repository);

    expect($service->listProducts($query))->toBe($paginator);
});

it('returns an active product provided by its repository', function () {
    $product = Product::factory()->make(['id' => 42]);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturn($product);
    $service = new ProductService($repository);

    expect($service->getProduct('42'))->toBe($product);
});

it('raises model-not-found when the repository has no matching product', function () {
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturnNull();
    $service = new ProductService($repository);

    expect(fn () => $service->getProduct('42'))->toThrow(ModelNotFoundException::class);
});

it('raises model-not-found when the repository returns an inactive product', function () {
    $product = Product::factory()->inactive()->make(['id' => 42]);
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldReceive('findById')->once()->with(42)->andReturn($product);
    $service = new ProductService($repository);

    expect(fn () => $service->getProduct('42'))->toThrow(ModelNotFoundException::class);
});

it('rejects invalid IDs before invoking the repository', function (string $id) {
    $repository = Mockery::mock(ProductRepositoryInterface::class);
    $repository->shouldNotReceive('findById');
    $service = new ProductService($repository);

    expect(fn () => $service->getProduct($id))->toThrow(ModelNotFoundException::class);
})->with(['0', '-1', 'abc', '9223372036854775808']);
