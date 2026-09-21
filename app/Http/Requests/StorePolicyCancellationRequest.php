<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 Slice C — Policy cancellation validation.
 */
final class StorePolicyCancellationRequest extends FormRequest
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
            'method' => ['required', Rule::in(['pro_rata', 'short_rate'])],
            'cancellation_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'short_rate_penalty' => ['nullable', 'required_if:method,short_rate', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
