<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Http\Requests\Product\ProductQueryRequest as CatalogueQueryRequest;
use App\Models\Product;
use Illuminate\Validation\Rule;

class ProductQueryRequest extends CatalogueQueryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['sometimes', 'required', Rule::enum(ProductStatus::class)]];
    }

    public function statusFilter(): ?ProductStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : ProductStatus::from($status);
    }
}
