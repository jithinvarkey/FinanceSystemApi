<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P5 — Bank reconciliation: the statement date/balance and the GL transactions
 * cleared against it.
 */
final class StoreBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('banking.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'cleared_transaction_ids' => ['nullable', 'array'],
            'cleared_transaction_ids.*' => ['integer'],
        ];
    }
}
