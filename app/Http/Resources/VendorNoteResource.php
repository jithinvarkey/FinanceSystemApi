<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\VendorNote
 */
final class VendorNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_number' => $this->note_number,
            'note_type' => $this->note_type->value,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor->name),
            'original_invoice_id' => $this->original_invoice_id,
            'note_date' => $this->note_date?->toDateString(),
            'reason' => $this->reason,
            'description' => $this->description,
            'currency_code' => $this->currency_code,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l): array => [
                'id' => $l->id, 'account_id' => $l->account_id, 'account_name' => $l->account?->name,
                'description' => $l->description, 'amount' => $l->amount, 'tax_code_id' => $l->tax_code_id,
                'tax_amount' => $l->tax_amount, 'line_total' => $l->line_total,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
