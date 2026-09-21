<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation for FIN-0005 cost centers. */
final class StoreCostCenterRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:20',
                Rule::unique('cost_centers', 'code')->ignore($this->route('cost_center'))->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
