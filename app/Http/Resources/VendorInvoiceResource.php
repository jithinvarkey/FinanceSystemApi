<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VendorInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorInvoice
 */
final class VendorInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor->name),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'currency_code' => $this->currency_code,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'reverse_charge' => (bool) $this->reverse_charge,
            'amount_paid' => $this->amount_paid,
            'balance_due' => number_format($this->balanceDue(), 2, '.', ''),
            'status' => $this->status->value,
            'is_opening' => (bool) $this->is_opening,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'fiscal_period_id' => $this->fiscal_period_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => VendorInvoiceLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
