<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for FIN-0004 VAT / tax rules. */
final class StoreTaxCodeRequest extends FormRequest
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
        return [
            'code' => ['required', 'string', 'max:20',
                Rule::unique('tax_codes', 'code')->ignore($this->route('tax_code'))->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'tax_type' => ['required', Rule::in(['input', 'output', 'both'])],
            'rate' => ['required', 'numeric', 'between:0,100'],
            'input_account_id' => ['nullable', 'required_if:tax_type,input,both', 'integer',
                Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'output_account_id' => ['nullable', 'required_if:tax_type,output,both', 'integer',
                Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'is_recoverable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input_account_id.required_if' => 'Input tax needs a recoverable VAT account.',
            'output_account_id.required_if' => 'Output tax needs a VAT payable account.',
        ];
    }
}
