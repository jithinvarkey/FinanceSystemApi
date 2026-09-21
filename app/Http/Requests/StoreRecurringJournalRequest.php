<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates recurring journal template payloads (FIN-0039).
 */
final class StoreRecurringJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('general-ledger.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:monthly,quarterly,yearly'],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'day_of_month.between' => 'Day of month must be 1–28 so every month has the date.',
        ];
    }
}
