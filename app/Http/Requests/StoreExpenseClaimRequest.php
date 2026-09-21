<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P6 — Create/update an expense claim. Reuses the AP permission set.
 */
final class StoreExpenseClaimRequest extends FormRequest
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
            'claimant' => ['required', 'string', 'max:120'],
            'expense_date' => ['required', 'date'],
            'credit_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cheque', 'cash', 'online'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
        ];
    }
}
