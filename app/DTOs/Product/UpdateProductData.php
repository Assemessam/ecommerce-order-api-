<?php

namespace App\DTOs\Product;

use App\Enums\ProductStatus;
use InvalidArgumentException;

final readonly class UpdateProductData
{
    private const array FIELDS = ['name', 'sku', 'description', 'price_minor', 'status', 'stock_adjustment'];

    /** @param array<string, true> $providedFields */
    private function __construct(
        public ?string $name,
        public ?string $sku,
        public ?string $description,
        public ?int $priceMinor,
        public ?ProductStatus $status,
        public ?int $stockAdjustment,
        private array $providedFields,
    ) {}

    /** @param array{name?: string, sku?: string, description?: ?string, price_minor?: int, status?: string, stock_adjustment?: int} $data */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'sku', 'status'] as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be a string.");
            }
        }

        if (array_key_exists('description', $data) && $data['description'] !== null && ! is_string($data['description'])) {
            throw new InvalidArgumentException('The description field must be a string or null.');
        }

        foreach (['price_minor', 'stock_adjustment'] as $field) {
            if (array_key_exists($field, $data) && ! is_int($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be an integer.");
            }
        }

        return new self(
            name: $data['name'] ?? null,
            sku: $data['sku'] ?? null,
            description: $data['description'] ?? null,
            priceMinor: $data['price_minor'] ?? null,
            status: array_key_exists('status', $data) ? ProductStatus::from($data['status']) : null,
            stockAdjustment: $data['stock_adjustment'] ?? null,
            providedFields: array_fill_keys(array_intersect(array_keys($data), self::FIELDS), true),
        );
    }

    public function has(string $field): bool
    {
        return isset($this->providedFields[$field]);
    }

    public function hasStockAdjustment(): bool
    {
        return $this->has('stock_adjustment');
    }

    /** @return array{name?: string, sku?: string, description?: ?string, price_minor?: int, status?: string} */
    public function toPersistenceArray(): array
    {
        $attributes = [];

        foreach ([
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price_minor' => $this->priceMinor,
            'status' => $this->status?->value,
        ] as $field => $value) {
            if ($this->has($field)) {
                $attributes[$field] = $value;
            }
        }

        return $attributes;
    }
}
