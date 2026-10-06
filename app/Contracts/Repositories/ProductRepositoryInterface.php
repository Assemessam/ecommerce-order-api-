<?php

namespace App\Contracts\Repositories;

use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface
{
    /** @return LengthAwarePaginator<int, Product> */
    public function paginate(ProductQuery $query, ?ProductStatus $status): LengthAwarePaginator;

    /** @param array{name: string, sku: string, price_minor: int, description?: ?string, stock_quantity?: int, status?: string} $attributes */
    public function create(array $attributes): Product;

    /** @param array{name?: string, sku?: string, price_minor?: int, description?: ?string, stock_quantity?: int, status?: string} $attributes */
    public function update(Product $product, array $attributes): Product;

    public function findById(int $id): ?Product;

    public function findByIdForUpdate(int $id): ?Product;

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    public function lockByIds(array $ids): Collection;

    public function restoreStock(Product $product, int $quantity): bool;

    public function deductStock(Product $product, int $quantity): bool;
}
