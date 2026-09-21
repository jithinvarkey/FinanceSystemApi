<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P4.5 — Blocking a customer requires a reason for the audit trail.
 */
final class BlockCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts-receivable.manage') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
