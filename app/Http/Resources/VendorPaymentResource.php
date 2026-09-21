<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VendorPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorPayment
 */
final class VendorPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor->name),
            'payment_date' => $this->payment_date?->toDateString(),
            'bank_account_id' => $this->bank_account_id,
            'bank_account_name' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount->name),
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'currency_code' => $this->currency_code,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->id,
                'vendor_invoice_id' => $a->vendor_invoice_id,
                'invoice_number' => $a->invoice?->invoice_number,
                'amount' => $a->amount,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
