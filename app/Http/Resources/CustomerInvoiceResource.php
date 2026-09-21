<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerInvoice
 */
final class CustomerInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'currency_code' => $this->currency_code,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'balance_due' => number_format($this->balanceDue(), 2, '.', ''),
            'status' => $this->status->value,
            'is_opening' => (bool) $this->is_opening,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'fiscal_period_id' => $this->fiscal_period_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => CustomerInvoiceLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
