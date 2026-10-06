<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'quantity' => ['bail', 'required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
        ];
    }
}
