<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inbound integration (P-INT) — a PUSHed renewal. `policy_external_id` targets
 * the expiring policy; all term/premium fields are optional overrides (default
 * to the expiring policy's values).
 */
final class IngestRenewalRequest extends FormRequest
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
            'policy_external_id' => ['required', 'string', 'max:80'],
            'product_code' => ['nullable', 'string', 'max:40'],
            'net_premium' => ['nullable', 'numeric', 'min:0.01'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'insurer_policy_no' => ['nullable', 'string', 'max:60'],
            'quote_number' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['nullable', Rule::in(['full', 'installment'])],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:60', 'required_if:payment_method,installment'],
        ];
    }
}
