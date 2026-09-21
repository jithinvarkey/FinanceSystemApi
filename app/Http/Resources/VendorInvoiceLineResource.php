<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VendorInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorInvoiceLine
 */
final class VendorInvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => $this->line_no,
            'account_id' => $this->account_id,
            'account_code' => $this->whenLoaded('account', fn () => $this->account->code),
            'account_name' => $this->whenLoaded('account', fn () => $this->account->name),
            'cost_center_id' => $this->cost_center_id,
            'description' => $this->description,
            'amount' => $this->amount,
            'tax_code_id' => $this->tax_code_id,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
        ];
    }
}
