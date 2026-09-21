<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P2.9 — Opening-balance cutover journal validation.
 */
final class StoreOpeningBalanceRequest extends FormRequest
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
        $postable = Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)->whereNull('deleted_at');

        return [
            'cutover_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'equity_account_id' => ['nullable', $postable],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', $postable],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
