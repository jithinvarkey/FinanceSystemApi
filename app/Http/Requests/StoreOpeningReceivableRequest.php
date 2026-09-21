<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P2.9b — bring a still-open customer invoice in at cutover. The original
 * invoice number is preserved (and must be unique); the GL leg posts at the
 * cutover date while the invoice keeps its original date for aging.
 */
final class StoreOpeningReceivableRequest extends FormRequest
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
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'invoice_number' => ['required', 'string', 'max:30', Rule::unique('customer_invoices', 'invoice_number')->whereNull('deleted_at')],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'outstanding_amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'equity_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
        ];
    }
}
