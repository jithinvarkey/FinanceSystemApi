<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P8 — Create/update a budget. Reuses the GL permission set.
 */
final class StoreBudgetRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'fiscal_year_id' => ['required', Rule::exists('fiscal_years', 'id')],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'lines.*.annual_amount' => ['required', 'numeric'],
        ];
    }
}
