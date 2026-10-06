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
    public function paginate(ProductQuery $query, ProductStatus $status): LengthAwarePaginator;

    public function findById(int $id): ?Product;

    public function findByIdForUpdate(int $id): ?Product;

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    public function lockByIds(array $ids): Collection;

    public function deductStock(Product $product, int $quantity): bool;
}
