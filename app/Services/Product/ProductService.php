<?php

namespace App\Services\Product;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductService
{
    public function __construct(private ProductRepositoryInterface $products, private ProductCatalogueCache $catalogueCache) {}

    /** @return LengthAwarePaginator<int, Product> */
    public function listProducts(ProductQuery $query): LengthAwarePaginator
    {
        return $this->catalogueCache->paginate($query, fn (): LengthAwarePaginator => $this->products->paginate($query, ProductStatus::Active));
    }

    public function getProduct(string $id): Product
    {
        $productId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $product = $productId === false ? null : $this->products->findById($productId);

        if ($product === null || $product->status !== ProductStatus::Active) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        }

        return $product;
    }
}
