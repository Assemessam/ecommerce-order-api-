<?php

namespace App\Services\Product;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\DTOs\Product\ProductQuery;
use App\Enums\ProductStatus;
use App\Exceptions\Domain\AdministrationConflictException;
use App\Exceptions\Domain\InventoryAdjustmentConflictException;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductService
{
    private const array EDITABLE_FIELDS = ['name', 'sku', 'description', 'price_minor', 'status'];

    public function __construct(private ProductRepositoryInterface $products, private ProductCatalogueCache $catalogueCache) {}

    /** @return LengthAwarePaginator<int, Product> */
    public function listProducts(ProductQuery $query): LengthAwarePaginator
    {
        return $this->catalogueCache->paginate($query, fn (): LengthAwarePaginator => $this->products->paginate($query, ProductStatus::Active));
    }

    public function getProduct(string $id): Product
    {
        $product = $this->products->findById($this->productId($id));

        if ($product === null || $product->status !== ProductStatus::Active) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        }

        return $product;
    }

    /** @return LengthAwarePaginator<int, Product> */
    public function listAdminProducts(User $actor, ProductQuery $query, ?ProductStatus $status = null): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Product::class);

        return $this->products->paginate($query, $status);
    }

    public function getAdminProduct(User $actor, string $id): Product
    {
        Gate::forUser($actor)->authorize('view', Product::class);

        return $this->products->findById($this->productId($id))
            ?? throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
    }

    /** @param array{name: string, sku: string, price_minor: int, description?: ?string, stock_quantity?: int, status?: string} $data */
    public function createProduct(User $actor, array $data): Product
    {
        Gate::forUser($actor)->authorize('create', Product::class);

        return $this->mutate(function () use ($data): Product {
            $product = $this->products->create(Arr::only($data, [...self::EDITABLE_FIELDS, 'stock_quantity']));
            $this->catalogueCache->invalidateAfterCommit();

            return $product;
        });
    }

    /** @param array{name?: string, sku?: string, price_minor?: int, description?: ?string, stock_adjustment?: int, status?: string} $data */
    public function updateProduct(User $actor, string $id, array $data): Product
    {
        Gate::forUser($actor)->authorize('update', Product::class);

        if (array_key_exists('stock_adjustment', $data)) {
            Gate::forUser($actor)->authorize('adjustInventory', Product::class);
        }

        $productId = $this->productId($id);

        return $this->mutate(function () use ($productId, $data): Product {
            $product = $this->products->findByIdForUpdate($productId)
                ?? throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
            $attributes = Arr::only($data, self::EDITABLE_FIELDS);

            if (isset($data['stock_adjustment'])) {
                $adjustment = $data['stock_adjustment'];

                /** Bounds are checked before addition can become a float. The row remains locked through commit. */
                if (($adjustment < 0 && $adjustment < -$product->stock_quantity)
                    || ($adjustment > 0 && $product->stock_quantity > PHP_INT_MAX - $adjustment)) {
                    throw new InventoryAdjustmentConflictException;
                }

                $attributes['stock_quantity'] = $product->stock_quantity + $adjustment;
            }

            if ($attributes === []) {
                return $product;
            }

            $product = $this->products->update($product, $attributes);

            if ($product->wasChanged()) {
                $this->catalogueCache->invalidateAfterCommit();
            }

            return $product;
        });
    }

    private function productId(string $id): int
    {
        $productId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($productId === false) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        }

        return $productId;
    }

    /** @param Closure(): Product $operation */
    private function mutate(Closure $operation): Product
    {
        try {
            return DB::transaction($operation, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'products_sku_normalized_unique')) {
                throw ValidationException::withMessages(['sku' => ['The SKU has already been taken.']]);
            }

            throw $exception;
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new AdministrationConflictException;
            }

            throw $exception;
        }
    }
}
