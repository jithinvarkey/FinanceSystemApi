<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates journal create/update payloads (FIN-0036).
 * Balance and debit-xor-credit rules live in JournalService; this request
 * guarantees shape, types, and referential integrity only.
 */
final class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('general-ledger.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lines.*.dimension_id' => ['nullable', 'integer', 'exists:gl_dimensions,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'lines.*.currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.min' => 'A journal requires at least two lines forming a balanced entry.',
            'lines.*.account_id.exists' => 'One of the selected accounts does not exist.',
        ];
    }
}
