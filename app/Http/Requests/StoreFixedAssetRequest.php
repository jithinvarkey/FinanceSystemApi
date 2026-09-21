<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P7 — Register a fixed asset. Reuses the GL permission set.
 */
final class StoreFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('general-ledger.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = fn () => Rule::exists('chart_of_accounts', 'id')->where('is_postable', true);

        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:80'],
            'acquisition_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lt:cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'depreciation_method' => ['nullable', 'in:straight_line,reducing_balance'],
            'is_cwip' => ['sometimes', 'boolean'],
            'asset_account_id' => ['required', $account()],
            'accum_depreciation_account_id' => ['required', $account()],
            'depreciation_expense_account_id' => ['required', $account()],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'location' => ['nullable', 'string', 'max:120'],
            'custodian' => ['nullable', 'string', 'max:120'],
        ];
    }
}
