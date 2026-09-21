<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inbound integration (P-INT) — a PUSHed policy. References parties by their
 * codes (resolved to ids in the service); auth is the integration-key
 * middleware, so authorize() is open here.
 */
final class IngestPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:80'],
            'customer_code' => ['required', 'string', 'max:40'],
            'insurer_code' => ['required', 'string', 'max:40'],
            'product_code' => ['required', 'string', 'max:40'],
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
}
