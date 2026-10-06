<?php

namespace App\Http\Requests\Admin;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PromotionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Promotion::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $optionalPositive = ['bail', 'nullable', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX];
        $date = ['bail', 'nullable', 'string', 'date_format:Y-m-d\\TH:i:sP,Y-m-d\\TH:i:s.uP'];

        return [
            'code' => ['bail', 'required', 'string', 'max:64', 'regex:/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'value' => ['bail', 'required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
            'minimum_cart_amount_minor' => ['bail', 'sometimes', 'required', 'integer:strict', 'min:0', 'max:'.PHP_INT_MAX],
            'maximum_discount_minor' => $optionalPositive,
            'global_usage_limit' => $optionalPositive,
            'per_customer_usage_limit' => $optionalPositive,
            'starts_at' => $date,
            'expires_at' => $date,
            'is_active' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }

    /** @return array<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->hasAny(['type', 'value'])
                && $this->input('type') === PromotionType::Percentage->value
                && $this->input('value') > 10000) {
                $validator->errors()->add('value', 'A percentage discount must not exceed 10000 basis points.');
            }

            if (! $validator->errors()->hasAny(['starts_at', 'expires_at'])
                && $this->filled('starts_at') && $this->filled('expires_at')
                && CarbonImmutable::parse($this->input('starts_at'))->gte(CarbonImmutable::parse($this->input('expires_at')))) {
                $validator->errors()->add('expires_at', 'The expiration must be after the start date.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Promotion::normalizeCode($this->input('code'))]);
        }

        foreach (['starts_at', 'expires_at'] as $field) {
            $date = $this->input($field);

            if (is_string($date) && str_ends_with($date, 'Z')) {
                $this->merge([$field => substr($date, 0, -1).'+00:00']);
            }
        }
    }
}
