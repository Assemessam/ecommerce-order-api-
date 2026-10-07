<?php

namespace App\DTOs\Product;

use App\Enums\ProductStatus;
use InvalidArgumentException;

final readonly class CreateProductData
{
    private const array FIELDS = ['name', 'sku', 'price_minor', 'description', 'stock_quantity', 'status'];

    /** @param array<string, true> $providedFields */
    private function __construct(
        public string $name,
        public string $sku,
        public int $priceMinor,
        public ?string $description = null,
        public ?int $stockQuantity = null,
        public ?ProductStatus $status = null,
        private array $providedFields = [],
    ) {}

    /** @param array{name: string, sku: string, price_minor: int, description?: ?string, stock_quantity?: int, status?: string} $data */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'sku'] as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be a string.");
            }
        }

        if (! array_key_exists('price_minor', $data) || ! is_int($data['price_minor'])) {
            throw new InvalidArgumentException('The price_minor field must be an integer.');
        }

        if (array_key_exists('description', $data) && $data['description'] !== null && ! is_string($data['description'])) {
            throw new InvalidArgumentException('The description field must be a string or null.');
        }

        if (array_key_exists('stock_quantity', $data) && ! is_int($data['stock_quantity'])) {
            throw new InvalidArgumentException('The stock_quantity field must be an integer.');
        }

        if (array_key_exists('status', $data) && ! is_string($data['status'])) {
            throw new InvalidArgumentException('The status field must be a string.');
        }

        return new self(
            name: $data['name'],
            sku: $data['sku'],
            priceMinor: $data['price_minor'],
            description: $data['description'] ?? null,
            stockQuantity: $data['stock_quantity'] ?? null,
            status: array_key_exists('status', $data) ? ProductStatus::from($data['status']) : null,
            providedFields: array_fill_keys(array_intersect(array_keys($data), self::FIELDS), true),
        );
    }

    /** @return array{name: string, sku: string, price_minor: int, description?: ?string, stock_quantity?: int, status?: string} */
    public function toPersistenceArray(): array
    {
        $attributes = [
            'name' => $this->name,
            'sku' => $this->sku,
            'price_minor' => $this->priceMinor,
        ];

        foreach ([
            'description' => $this->description,
            'stock_quantity' => $this->stockQuantity,
            'status' => $this->status?->value,
        ] as $field => $value) {
            if (isset($this->providedFields[$field])) {
                $attributes[$field] = $value;
            }
        }

        return $attributes;
    }
}
