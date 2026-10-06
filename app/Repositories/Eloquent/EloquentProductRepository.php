<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function paginate(ProductQuery $query, ProductStatus $status): LengthAwarePaginator
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

        $products = Product::query()->where('status', $status);

        if ($query->search !== null && $query->search !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->search);
            $products->whereLike('name', '%'.$search.'%');
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

    public function findByIdForUpdate(int $id): ?Product
    {
        return Product::query()->lockForUpdate()->find($id);
    }
}
