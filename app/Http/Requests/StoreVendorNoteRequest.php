<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update an AP credit or debit note.
 */
final class StoreVendorNoteRequest extends FormRequest
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
            'note_type' => ['required', Rule::in(['credit', 'debit'])],
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'original_invoice_id' => ['nullable', Rule::exists('vendor_invoices', 'id')],
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
