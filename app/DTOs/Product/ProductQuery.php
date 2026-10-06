<?php

namespace App\DTOs\Product;

final readonly class ProductQuery
{
    public const array SORT_FIELDS = ['name', 'price', 'created_at'];

    public const array SORT_DIRECTIONS = ['asc', 'desc'];

    public const int DEFAULT_PAGE_SIZE = 15;

    public const int MAX_PAGE_SIZE = 100;

    public function __construct(
        public ?string $search = null,
        public ?int $minPrice = null,
        public ?int $maxPrice = null,
        public ?bool $available = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $page = 1,
        public int $perPage = self::DEFAULT_PAGE_SIZE,
    ) {}
}
