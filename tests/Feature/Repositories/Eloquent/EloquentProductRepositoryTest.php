<?php

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Repositories\Eloquent\EloquentProductRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('resolves the repository contract and queries PostgreSQL with all filters', function () {
    $first = Product::factory()->create(['name' => 'Desk Lamp A', 'price_minor' => 2500]);
    $second = Product::factory()->create(['name' => 'Desk Lamp B', 'price_minor' => 3000]);
    Product::factory()->inactive()->create(['name' => 'Desk Lamp C', 'price_minor' => 2500]);
    Product::factory()->outOfStock()->create(['name' => 'Desk Lamp D', 'price_minor' => 2500]);
    Product::factory()->create(['name' => 'Desk Lamp E', 'price_minor' => 4000]);
    $repository = app(ProductRepositoryInterface::class);

    $page = $repository->paginate(new ProductQuery(search: 'LAMP', minPrice: 2500, maxPrice: 3000, available: true, sort: 'price', direction: 'asc', page: 2, perPage: 1), ProductStatus::Active);

    expect($repository)->toBeInstanceOf(EloquentProductRepository::class);
    expect($page->total())->toBe(2);
    expect($page->items()[0]->id)->toBe($second->id);
    expect($repository->findById($first->id)->id)->toBe($first->id);
    expect($repository->findById(999999))->toBeNull();
});

it('uses the status chosen by the service rather than a hidden global scope', function () {
    Product::factory()->create();
    $inactive = Product::factory()->inactive()->create();
    $repository = app(ProductRepositoryInterface::class);

    $page = $repository->paginate(new ProductQuery, ProductStatus::Inactive);

    expect($page->total())->toBe(1);
    expect($page->items()[0]->id)->toBe($inactive->id);
});

it('refuses unchecked sort identifiers and directions from non-HTTP callers', function (ProductQuery $query) {
    $repository = app(ProductRepositoryInterface::class);

    expect(fn () => $repository->paginate($query, ProductStatus::Active))->toThrow(InvalidArgumentException::class);
})->with([
    'column injection' => new ProductQuery(sort: 'price; DROP TABLE products'),
    'direction injection' => new ProductQuery(direction: 'asc; DROP TABLE products'),
]);
