<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EndorsementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inbound integration (P-INT) — a PUSHed endorsement. `policy_external_id`
 * targets an already-ingested policy by the upstream's own id.
 */
final class IngestEndorsementRequest extends FormRequest
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
            'type' => ['required', Rule::enum(EndorsementType::class)],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'delta_net_premium' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
