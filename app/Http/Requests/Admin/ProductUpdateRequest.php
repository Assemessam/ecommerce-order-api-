<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;

class ProductUpdateRequest extends ProductStoreRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        if ($actor === null || ! $actor->can('update', Product::class)) {
            return false;
        }

        return ! $this->exists('stock_adjustment') || $actor->can('adjustInventory', Product::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['name', 'sku', 'price_minor'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        $rules['stock_quantity'] = ['missing'];
        $rules['stock_adjustment'] = ['bail', 'sometimes', 'required', 'integer:strict', 'min:'.(-PHP_INT_MAX), 'max:'.PHP_INT_MAX, 'not_in:0'];

        return $rules;
    }
}
