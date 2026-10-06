<?php

namespace App\Http\Requests\Product;

use App\DTOs\Product\ProductQuery;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'search' => [
                'bail', 'nullable', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (str_contains($value, "\0")) {
                        $fail('The search must not contain null bytes.');
                    }
                },
            ],
            'min_price' => ['bail', 'sometimes', 'required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'max_price' => ['bail', 'sometimes', 'required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'available' => ['sometimes', 'required', 'boolean'],
            'sort' => ['sometimes', 'required', Rule::in(ProductQuery::SORT_FIELDS)],
            'direction' => ['sometimes', 'required', Rule::in(ProductQuery::SORT_DIRECTIONS)],
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:'.ProductQuery::MAX_PAGE_SIZE],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['min_price', 'max_price'])) {
                return;
            }

            $minimum = $this->query('min_price');
            $maximum = $this->query('max_price');

            if ($minimum !== null && $maximum !== null && (int) $minimum > (int) $maximum) {
                $validator->errors()->add('max_price', 'The maximum price must be greater than or equal to the minimum price.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function toQuery(): ProductQuery
    {
        $data = $this->validated();

        return new ProductQuery(
            search: $data['search'] ?? null,
            minPrice: isset($data['min_price']) ? (int) $data['min_price'] : null,
            maxPrice: isset($data['max_price']) ? (int) $data['max_price'] : null,
            available: isset($data['available']) ? (bool) $data['available'] : null,
            sort: $data['sort'] ?? 'created_at',
            direction: $data['direction'] ?? 'desc',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? ProductQuery::DEFAULT_PAGE_SIZE),
        );
    }

    protected function prepareForValidation(): void
    {
        $search = $this->query('search');

        if (is_string($search)) {
            $this->query->set('search', trim($search) === '' ? null : trim($search));
        }

        $available = $this->query('available');

        if (in_array($available, ['true', 'false'], true)) {
            $this->query->set('available', $available === 'true');
        }
    }
}
