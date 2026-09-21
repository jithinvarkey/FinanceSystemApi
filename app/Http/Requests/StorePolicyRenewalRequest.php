<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 Slice E — Policy renewal. All fields optional: anything omitted is
 * carried over from the expiring policy (dates default to the next term).
 */
final class StorePolicyRenewalRequest extends FormRequest
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
            'product_id' => ['nullable', Rule::exists('products', 'id')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'net_premium' => ['nullable', 'numeric', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['full', 'installment'])],
            'installment_count' => ['nullable', 'required_if:payment_method,installment', 'integer', 'min:2', 'max:60'],
            'insurer_policy_no' => ['nullable', 'string', 'max:60'],
            'quote_number' => ['nullable', 'string', 'max:60'],
        ];
    }
}
