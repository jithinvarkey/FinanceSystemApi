<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lob_id' => $this->lob_id,
            'lob_name' => $this->whenLoaded('lob', fn () => $this->lob->name),
            'is_life' => $this->whenLoaded('lob', fn () => (bool) $this->lob->is_life),
            'code' => $this->code,
            'name' => $this->name,
            'default_commission_rate' => $this->default_commission_rate,
            'default_tax_code_id' => $this->default_tax_code_id,
            'commission_revenue_account_id' => $this->commission_revenue_account_id,
            'status' => $this->status,
        ];
    }
}
