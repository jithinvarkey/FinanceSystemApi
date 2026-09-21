<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Budget
 */
final class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fiscal_year_id' => $this->fiscal_year_id,
            'fiscal_year' => $this->whenLoaded('fiscalYear', fn () => $this->fiscalYear->code),
            'status' => $this->status,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l): array => [
                'id' => $l->id,
                'account_id' => $l->account_id,
                'account_code' => $l->account?->code,
                'account_name' => $l->account?->name,
                'annual_amount' => $l->annual_amount,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
