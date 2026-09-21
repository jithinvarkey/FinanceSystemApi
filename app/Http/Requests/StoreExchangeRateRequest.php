<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for FIN-0003 dated exchange rates. */
final class StoreExchangeRateRequest extends FormRequest
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
            'rate_date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
