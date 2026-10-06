<?php

namespace App\Http\Requests\Admin;

use App\Models\Promotion;

class PromotionUpdateRequest extends PromotionStoreRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Promotion::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['code', 'type', 'value'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
