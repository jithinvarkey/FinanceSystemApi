<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P2.9b — bring a still-open vendor invoice in at cutover. Mirrors the
 * receivable variant; the contra side is Opening Balance Equity.
 */
final class StoreOpeningPayableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('general-ledger.post') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cutover_date' => ['required', 'date'],
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'invoice_number' => ['required', 'string', 'max:30', Rule::unique('vendor_invoices', 'invoice_number')->whereNull('deleted_at')],
            'vendor_invoice_no' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'outstanding_amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'equity_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
        ];
    }
}
