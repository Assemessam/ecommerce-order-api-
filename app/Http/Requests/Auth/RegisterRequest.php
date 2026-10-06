<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => [
                'bail', 'required', 'string', 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }

                    if (str_contains($value, "\0")) {
                        $fail('The password must not contain null bytes.');
                    }
                },
                Password::defaults(),
            ],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
