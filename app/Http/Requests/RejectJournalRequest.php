<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Rejection requires a documented reason (FIN-0037 audit requirement).
 */
final class RejectJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('general-ledger.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
