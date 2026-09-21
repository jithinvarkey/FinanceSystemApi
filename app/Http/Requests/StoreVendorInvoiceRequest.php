<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P3.6 — Vendor invoice header + lines validation.
 */
final class StoreVendorInvoiceRequest extends FormRequest
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
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'vendor_invoice_no' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'reverse_charge' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)->whereNull('deleted_at')],
            'lines.*.cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.account_id.exists' => 'Each line must use a postable GL account.',
        ];
    }
}
