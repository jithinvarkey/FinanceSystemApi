<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P3.11 — Vendor payment validation (header + invoice allocations).
 */
final class StoreVendorPaymentRequest extends FormRequest
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
            'payment_date' => ['required', 'date'],
            'bank_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)->whereNull('deleted_at')],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cheque', 'cash'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.vendor_invoice_id' => ['required', Rule::exists('vendor_invoices', 'id')->whereNull('deleted_at')],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
