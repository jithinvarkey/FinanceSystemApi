<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for FIN-0002 fiscal year creation. Overlap and sequencing
 * rules live in FiscalYearService.
 */
final class StoreFiscalYearRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:10', 'unique:fiscal_years,code'],
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after' => 'Fiscal year end date must come after the start date.',
        ];
    }
}
