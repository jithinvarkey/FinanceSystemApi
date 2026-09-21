<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 Slice B — Premium collection validation (header + installment allocations).
 */
final class StorePremiumCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('policies.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policy_id' => ['required', Rule::exists('policies', 'id')->whereNull('deleted_at')],
            'collection_date' => ['required', 'date'],
            'bank_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)->whereNull('deleted_at')],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cheque', 'cash', 'online'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.policy_installment_id' => ['required', Rule::exists('policy_installments', 'id')],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
