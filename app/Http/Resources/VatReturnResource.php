<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VatReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VatReturn
 */
final class VatReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'output_vat' => $this->output_vat,
            'input_vat' => $this->input_vat,
            'reverse_charge_base' => $this->reverse_charge_base,
            'reverse_charge_vat' => $this->reverse_charge_vat,
            'net_vat_payable' => $this->net_vat_payable,
            'status' => $this->status->value,
            'zatca_reference' => $this->zatca_reference,
            'notes' => $this->notes,
            'filed_at' => $this->filed_at?->toIso8601String(),
            'filed_by_name' => $this->whenLoaded('filedBy', fn () => $this->filedBy?->name),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_batch_number' => $this->payment_batch_number,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
