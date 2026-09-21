<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P6 — Create/update a petty cash voucher.
 */
final class StorePettyCashRequest extends FormRequest
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
            'voucher_date' => ['required', 'date'],
            'petty_cash_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'expense_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'payee' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
        ];
    }
}
