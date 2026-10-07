<?php

namespace App\DTOs\Promotion;

use App\Enums\PromotionType;
use InvalidArgumentException;

final readonly class CreatePromotionData
{
    private const array FIELDS = ['code', 'type', 'value', 'minimum_cart_amount_minor', 'maximum_discount_minor', 'starts_at', 'expires_at', 'global_usage_limit', 'per_customer_usage_limit', 'is_active'];

    /** @param array<string, true> $providedFields */
    private function __construct(
        public string $code,
        public PromotionType $type,
        public int $value,
        public ?int $minimumCartAmountMinor = null,
        public ?int $maximumDiscountMinor = null,
        public ?string $startsAt = null,
        public ?string $expiresAt = null,
        public ?int $globalUsageLimit = null,
        public ?int $perCustomerUsageLimit = null,
        public ?bool $isActive = null,
        private array $providedFields = [],
    ) {}

    /** @param array{code: string, type: string, value: int, minimum_cart_amount_minor?: int, maximum_discount_minor?: ?int, starts_at?: ?string, expires_at?: ?string, global_usage_limit?: ?int, per_customer_usage_limit?: ?int, is_active?: bool} $data */
    public static function fromArray(array $data): self
    {
        foreach (['code', 'type'] as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be a string.");
            }
        }

        if (! array_key_exists('value', $data) || ! is_int($data['value'])) {
            throw new InvalidArgumentException('The value field must be an integer.');
        }

        if (array_key_exists('minimum_cart_amount_minor', $data) && ! is_int($data['minimum_cart_amount_minor'])) {
            throw new InvalidArgumentException('The minimum_cart_amount_minor field must be an integer.');
        }

        foreach (['maximum_discount_minor', 'global_usage_limit', 'per_customer_usage_limit'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && ! is_int($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be an integer or null.");
            }
        }

        foreach (['starts_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && ! is_string($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be a string or null.");
            }
        }

        if (array_key_exists('is_active', $data) && ! is_bool($data['is_active'])) {
            throw new InvalidArgumentException('The is_active field must be a boolean.');
        }

        return new self(
            code: $data['code'],
            type: PromotionType::from($data['type']),
            value: $data['value'],
            minimumCartAmountMinor: $data['minimum_cart_amount_minor'] ?? null,
            maximumDiscountMinor: $data['maximum_discount_minor'] ?? null,
            startsAt: $data['starts_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            globalUsageLimit: $data['global_usage_limit'] ?? null,
            perCustomerUsageLimit: $data['per_customer_usage_limit'] ?? null,
            isActive: $data['is_active'] ?? null,
            providedFields: array_fill_keys(array_intersect(array_keys($data), self::FIELDS), true),
        );
    }

    /** @return array{code: string, type: string, value: int, minimum_cart_amount_minor?: int, maximum_discount_minor?: ?int, starts_at?: ?string, expires_at?: ?string, global_usage_limit?: ?int, per_customer_usage_limit?: ?int, is_active?: bool} */
    public function toPersistenceArray(): array
    {
        $attributes = ['code' => $this->code, 'type' => $this->type->value, 'value' => $this->value];

        foreach ([
            'minimum_cart_amount_minor' => $this->minimumCartAmountMinor,
            'maximum_discount_minor' => $this->maximumDiscountMinor,
            'starts_at' => $this->startsAt,
            'expires_at' => $this->expiresAt,
            'global_usage_limit' => $this->globalUsageLimit,
            'per_customer_usage_limit' => $this->perCustomerUsageLimit,
            'is_active' => $this->isActive,
        ] as $field => $value) {
            if (isset($this->providedFields[$field])) {
                $attributes[$field] = $value;
            }
        }

        return $attributes;
    }
}
