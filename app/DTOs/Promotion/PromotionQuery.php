<?php

namespace App\DTOs\Promotion;

use InvalidArgumentException;

final readonly class PromotionQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 15,
        public ?bool $isActive = null,
    ) {}

    /** @param array{page?: int|float|string|bool, per_page?: int|float|string|bool, is_active?: bool|int|string} $data */
    public static function fromArray(array $data): self
    {
        $page = filter_var(array_key_exists('page', $data) ? $data['page'] : 1, FILTER_VALIDATE_INT);
        $perPage = filter_var(array_key_exists('per_page', $data) ? $data['per_page'] : 15, FILTER_VALIDATE_INT);

        if ($page === false) {
            throw new InvalidArgumentException('The page field must be an integer.');
        }

        if ($perPage === false) {
            throw new InvalidArgumentException('The per_page field must be an integer.');
        }

        if (array_key_exists('is_active', $data) && ! in_array($data['is_active'], [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('The is_active field must be a boolean.');
        }

        return new self(
            page: $page,
            perPage: $perPage,
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        );
    }
}
