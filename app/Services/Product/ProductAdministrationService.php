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

class ProductAdministrationService
{
    private const array EDITABLE_FIELDS = ['name', 'sku', 'description', 'price_minor', 'status'];

    public function __construct(private ProductRepositoryInterface $products) {}

    /** @return LengthAwarePaginator<int, Product> */
    public function listProducts(User $user, ProductQuery $query, ?ProductStatus $status = null): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Product::class);

        return $this->products->paginate($query, $status);
    }

    public function getProduct(User $user, string $id): Product
    {
        Gate::forUser($user)->authorize('view', Product::class);

        return $this->products->findById($this->productId($id))
            ?? throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
    }

    /** @param array{name: string, sku: string, price_minor: int, description?: ?string, stock_quantity?: int, status?: string} $data */
    public function createProduct(User $user, array $data): Product
    {
        Gate::forUser($user)->authorize('create', Product::class);

        return $this->mutate(fn (): Product => $this->products->create(Arr::only($data, [...self::EDITABLE_FIELDS, 'stock_quantity'])));
    }

    /** @param array{name?: string, sku?: string, price_minor?: int, description?: ?string, stock_adjustment?: int, status?: string} $data */
    public function updateProduct(User $user, string $id, array $data): Product
    {
        Gate::forUser($user)->authorize('update', Product::class);
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

            return $this->products->update($product, $attributes);
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
