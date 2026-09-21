<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EndorsementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 — Policy endorsement create (one of the four financial types).
 */
final class StoreEndorsementRequest extends FormRequest
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
            'type' => ['required', Rule::enum(EndorsementType::class)],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'delta_net_premium' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'delta_net_premium.min' => 'Enter the premium change amount (a positive figure).',
        ];
    }
}
