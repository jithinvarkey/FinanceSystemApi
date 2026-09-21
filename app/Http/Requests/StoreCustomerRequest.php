<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.1 — Customer create/update validation incl. Saudi VAT number format.
 */
final class StoreCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'customer_type' => ['required', Rule::enum(CustomerType::class)],
            'trn' => ['nullable', 'digits:15'],
            'commercial_reg_no' => ['nullable', 'string', 'max:60'],
            'commercial_reg_expiry' => ['nullable', 'date'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:400'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'default_receivable_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'default_revenue_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'trn.digits' => 'The VAT number must be exactly 15 digits (Saudi VAT registration number).',
        ];
    }
}
