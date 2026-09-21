<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\VendorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P3.1/P3.3 — Vendor create/update validation incl. Saudi VAT number format.
 */
final class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts-payable.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'vendor_type' => ['required', Rule::enum(VendorType::class)],
            'trn' => ['nullable', 'digits:15'],
            'trade_license_no' => ['nullable', 'string', 'max:60'],
            'trade_license_expiry' => ['nullable', 'date'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:400'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settlement_discount_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'wht_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'default_payable_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'default_expense_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'trn.digits' => 'The VAT number must be exactly 15 digits (Saudi VAT registration number).',
        ];
    }
}
