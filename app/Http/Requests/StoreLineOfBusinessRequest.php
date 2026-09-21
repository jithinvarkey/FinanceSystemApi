<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P4.17 — Line of business create/update.
 */
final class StoreLineOfBusinessRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', Rule::exists('lines_of_business', 'id')],
            'is_life' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
