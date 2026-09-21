<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\FixedAsset
 */
final class FixedAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'name' => $this->name,
            'category' => $this->category,
            'location' => $this->location,
            'custodian' => $this->custodian,
            'cost_center_id' => $this->cost_center_id,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'cost' => $this->cost,
            'salvage_value' => $this->salvage_value,
            'useful_life_months' => $this->useful_life_months,
            'depreciation_method' => $this->depreciation_method,
            'is_cwip' => (bool) $this->is_cwip,
            'monthly_depreciation' => number_format($this->monthlyDepreciation(), 2, '.', ''),
            'accumulated_depreciation' => $this->accumulated_depreciation,
            'book_value' => number_format($this->bookValue(), 2, '.', ''),
            'status' => $this->status->value,
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($e): array => [
                'period' => sprintf('%04d-%02d', $e->period_year, $e->period_month),
                'entry_date' => $e->entry_date?->toDateString(),
                'amount' => $e->amount,
                'batch_number' => $e->batch_number,
            ])),
        ];
    }
}
