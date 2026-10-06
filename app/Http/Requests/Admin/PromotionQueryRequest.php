<?php

namespace App\Http\Requests\Admin;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;

class PromotionQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Promotion::class) ?? false;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->query('is_active'), ['true', 'false'], true)) {
            $this->query->set('is_active', $this->query('is_active') === 'true');
        }
    }
}
