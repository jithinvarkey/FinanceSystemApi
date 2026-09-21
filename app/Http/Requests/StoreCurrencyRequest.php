<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for FIN-0003 currency master. */
final class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance-config.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'alpha',
                Rule::unique('currencies', 'code')->ignore($this->route('currency'))],
            'name' => ['required', 'string', 'max:80'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimal_places' => ['sometimes', 'integer', 'between:0,4'],
            'is_base' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
