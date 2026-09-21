<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\VendorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 — Policy create/update. The insurer must be a vendor of type insurer.
 */
final class StorePolicyRequest extends FormRequest
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
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'insurer_id' => ['required', Rule::exists('vendors', 'id')->where('vendor_type', VendorType::Insurer->value)->whereNull('deleted_at')],
            'product_id' => ['required', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'insurer_policy_no' => ['nullable', 'string', 'max:60'],
            'quote_number' => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['full', 'installment'])],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:60', 'required_if:payment_method,installment'],
            'net_premium' => ['required', 'numeric', 'min:0.01'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'insurer_id.exists' => 'The insurer must be an active vendor of type "insurer".',
            'installment_count.required_if' => 'Choose how many installments (2 or more) for an installment policy.',
        ];
    }
}
