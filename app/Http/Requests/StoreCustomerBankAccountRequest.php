<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.4 — Customer bank account with IBAN / SWIFT format validation.
 */
final class StoreCustomerBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts-receivable.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:150'],
            'account_name' => ['required', 'string', 'max:180'],
            'account_number' => ['nullable', 'required_without:iban', 'string', 'max:50'],
            'iban' => ['nullable', 'required_without:account_number', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'swift_bic' => ['nullable', 'regex:/^[A-Z0-9]{8,11}$/'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'iban.regex' => 'Enter a valid IBAN (e.g. SA03 8000 0000 6080 1016 7519).',
            'swift_bic.regex' => 'Enter a valid 8 or 11 character SWIFT/BIC code.',
        ];
    }
}
