<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a recurring invoice/bill template.
 */
final class StoreRecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('general-ledger.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCustomer = $this->input('type') === 'customer';

        return [
            'type' => ['required', Rule::in(['customer', 'vendor'])],
            'party_id' => ['required', 'integer', Rule::exists($isCustomer ? 'customers' : 'vendors', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'is_active' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
        ];
    }
}
