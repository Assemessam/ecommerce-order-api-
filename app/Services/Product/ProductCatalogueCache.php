<?php

namespace App\Services\Product;

use App\DTOs\Product\ProductQuery;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RedisException;
use Throwable;

class ProductCatalogueCache
{
    private bool $unavailable = false;

    public function __construct(private Factory $cache, private LoggerInterface $logger) {}

    /**
     * Cache scalar resource inputs; reconstruct pagination URLs for each request.
     *
     * @param  Closure(): PaginatorContract<int, Product>  $load
     * @return PaginatorContract<int, Product>
     */
    public function paginate(ProductQuery $query, Closure $load): PaginatorContract
    {
        if (! config('catalogue.cache.enabled') || DB::transactionLevel() > 0) {
            return $load();
        }

        $startedAt = hrtime(true);
        $generation = $this->operation('generation', function (): ?string {
            $store = $this->store();
            $generation = $store->get($this->generationKey());

            if ($generation === null) {
                $store->add($this->generationKey(), (string) Str::uuid(), 86400);
                $generation = $store->get($this->generationKey());
            }

            return is_string($generation) ? $generation : null;
        });
        $key = $generation === null ? null : $this->key($query, $generation);
        $cached = $key === null ? null : $this->operation('read', fn (): mixed => $this->store()->get($key));

        if (is_array($cached)) {
            return new LengthAwarePaginator(
                array_map(fn (array $attributes): Product => (new Product)->newFromBuilder($attributes), $cached['items']),
                $cached['total'], $query->perPage, $query->page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()],
            );
        }

        $products = $load();
        $remainingTtl = (int) config('catalogue.cache.ttl') - (int) ceil((hrtime(true) - $startedAt) / 1_000_000_000);

        if ($key !== null && $remainingTtl > 0) {
            $payload = [
                'items' => collect($products->items())->map(fn (Product $product): array => Arr::only(
                    $product->getAttributes(),
                    ['id', 'name', 'sku', 'description', 'price_minor', 'stock_quantity', 'status', 'created_at', 'updated_at'],
                ))->all(),
                'total' => $products->total(),
            ];

            $this->operation('write', function () use ($generation, $key, $payload, $remainingTtl): void {
                if ($this->store()->get($this->generationKey()) === $generation
                    && ! $this->store()->put($key, $payload, $remainingTtl)) {
                    throw new RedisException('Catalogue cache write was refused.');
                }
            });
        }

        return $products;
    }

    public function invalidateAfterCommit(): void
    {
        if (config('catalogue.cache.enabled')) {
            DB::afterCommit(fn () => $this->invalidate());
        }
    }

    private function invalidate(): void
    {
        $this->operation('invalidate', function (): void {
            if (! $this->store()->forever($this->generationKey(), (string) Str::uuid())) {
                throw new RedisException('Catalogue cache invalidation was refused.');
            }
        });
    }

    private function generationKey(): string
    {
        return config('catalogue.cache.namespace').':v1:public:generation';
    }

    private function key(ProductQuery $query, string $generation): string
    {
        $parameters = [
            'search' => $query->search === '' ? null : $query->search,
            'min_price' => $query->minPrice,
            'max_price' => $query->maxPrice,
            'available' => $query->available,
            'sort' => $query->sort,
            'direction' => $query->direction,
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        return config('catalogue.cache.namespace').':v1:public:'.$generation.':'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }

    private function store(): Repository
    {
        return $this->cache->store(config('catalogue.cache.store'));
    }

    /** Only cache operations enter this boundary; database/application exceptions propagate. */
    private function operation(string $operation, Closure $callback): mixed
    {
        if ($this->unavailable) {
            return null;
        }

        try {
            return $callback();
        } catch (RedisException|InvalidArgumentException $exception) {
            $this->unavailable = true;

            /** Diagnostics must not turn a committed purchase into a failed response. */
            try {
                $warningKey = 'catalogue-cache-warning:'.hash('sha256', $this->generationKey());

                if ($this->cache->store('file')->add($warningKey, true, 60)) {
                    $this->logger->warning('Product catalogue cache unavailable; using PostgreSQL.', [
                        'operation' => $operation,
                        'store' => config('catalogue.cache.store'),
                        'exception_class' => $exception::class,
                    ]);
                }
            } catch (Throwable) {
                /** Diagnostic sink failures must not affect business results. */
            }

            return null;
        }
    }
}
