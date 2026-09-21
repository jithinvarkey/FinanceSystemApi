<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TaxCode
 */
final class TaxCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'tax_type' => $this->tax_type->value,
            'rate' => (float) $this->rate,
            'input_account' => new ChartOfAccountResource($this->whenLoaded('inputAccount')),
            'output_account' => new ChartOfAccountResource($this->whenLoaded('outputAccount')),
            'is_recoverable' => $this->is_recoverable,
            'status' => $this->status->value,
        ];
    }
}
