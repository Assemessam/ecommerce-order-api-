<?php

namespace App\Http\Requests\Cart;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;

class ApplyPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Promotion::normalizeCode($this->input('code'))]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['code' => ['bail', 'required', 'string', 'max:64', 'regex:/\\A[A-Z0-9][A-Z0-9_-]*\\z/D']];
    }
}
