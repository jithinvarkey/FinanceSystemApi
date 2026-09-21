<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Currency
 */
final class CurrencyResource extends JsonResource
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
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimal_places,
            'is_base' => $this->is_base,
            'status' => $this->status->value,
            'latest_rate' => $this->whenLoaded('exchangeRates',
                fn () => $this->exchangeRates->first()?->only(['rate', 'rate_date'])),
        ];
    }
}
