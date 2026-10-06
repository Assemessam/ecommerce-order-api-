<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Checkout has no body parameters; the header is the only accepted input. @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->headers->has('Idempotency-Key')
            ? ['idempotency_key' => $this->header('Idempotency-Key')]
            : [];
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['idempotency_key' => ['bail', 'sometimes', 'required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D']];
    }
}
