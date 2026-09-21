<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update an AR credit or debit note.
 */
final class StoreCustomerNoteRequest extends FormRequest
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
            'note_type' => ['required', Rule::in(['credit', 'debit'])],
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'original_invoice_id' => ['nullable', Rule::exists('customer_invoices', 'id')],
            'note_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
        ];
    }
}
