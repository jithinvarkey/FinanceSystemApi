<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for FIN-0001 account creation/update.
 */
final class StoreChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance-config.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('chart_of_account');

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\-\.]+$/i',
                Rule::unique('chart_of_accounts', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'parent_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'is_postable' => ['sometimes', 'boolean'],
            'is_bank_account' => ['sometimes', 'boolean'],
            'is_control_account' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This account code is already in use.',
            'code.regex' => 'Account codes may contain letters, numbers, dashes and dots only.',
            'account_type.in' => 'Account type must be asset, liability, equity, revenue or expense.',
        ];
    }
}
