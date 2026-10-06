<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function paginate(ProductQuery $query, ?ProductStatus $status): LengthAwarePaginator
    {
        $sortColumn = match ($query->sort) {
            'name' => 'name',
            'price' => 'price_minor',
            'created_at' => 'created_at',
            default => throw new InvalidArgumentException('Unsupported product sort field.'),
        };

        if (! in_array($query->direction, ProductQuery::SORT_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Unsupported product sort direction.');
        }

        $products = Product::query();

        if ($status !== null) {
            $products->where('status', $status);
        }

        if ($query->search !== null && $query->search !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->search);
            $products->where(function (Builder $products) use ($search): void {
                $products->whereLike('name', '%'.$search.'%')
                    ->orWhereLike('sku', '%'.$search.'%')
                    ->orWhereLike('description', '%'.$search.'%');
            });
        }

        if ($query->minPrice !== null) {
            $products->where('price_minor', '>=', $query->minPrice);
        }

        if ($query->maxPrice !== null) {
            $products->where('price_minor', '<=', $query->maxPrice);
        }

        if ($query->available !== null) {
            $products->where('stock_quantity', $query->available ? '>' : '=', 0);
        }

        return $products->orderBy($sortColumn, $query->direction)
            ->orderBy('id', $query->direction)
            ->paginate($query->perPage, ['*'], 'page', $query->page);
    }

    public function findById(int $id): ?Product
    {
        return Product::query()->find($id);
    }

    public function create(array $attributes): Product
    {
        return Product::query()->create($attributes)->refresh();
    }

    public function update(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        return $product->refresh();
    }

    public function findByIdForUpdate(int $id): ?Product
    {
        return Product::query()->lockForUpdate()->find($id);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    public function lockByIds(array $ids): Collection
    {
        return Product::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function restoreStock(Product $product, int $quantity): bool
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Inventory restoration must be positive.');
        }

        return Product::query()->whereKey($product->id)->where('stock_quantity', '<=', PHP_INT_MAX - $quantity)
            ->increment('stock_quantity', $quantity) === 1;
    }

    public function deductStock(Product $product, int $quantity): bool
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Inventory deduction must be positive.');
        }

        return Product::query()->whereKey($product->id)->where('stock_quantity', '>=', $quantity)
            ->decrement('stock_quantity', $quantity) === 1;
    }
}
