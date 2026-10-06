<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\x00/'],
            'sku' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\x00/'],
            'description' => ['bail', 'nullable', 'string', 'max:10000', 'not_regex:/\x00/'],
            'price_minor' => ['bail', 'required', 'integer:strict', 'min:0', 'max:'.PHP_INT_MAX],
            'stock_quantity' => ['bail', 'sometimes', 'required', 'integer:strict', 'min:0', 'max:'.PHP_INT_MAX],
            'status' => ['sometimes', 'required', Rule::enum(ProductStatus::class)],
            'stock_adjustment' => ['missing'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'sku'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
