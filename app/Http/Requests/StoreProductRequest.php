<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 — Product create/update.
 */
final class StoreProductRequest extends FormRequest
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
            'lob_id' => ['required', Rule::exists('lines_of_business', 'id')],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'default_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_code_id' => ['nullable', Rule::exists('tax_codes', 'id')],
            'commission_revenue_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
