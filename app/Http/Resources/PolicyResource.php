<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Policy
 */
final class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_number' => $this->policy_number,
            'insurer_policy_no' => $this->insurer_policy_no,
            'quote_number' => $this->quote_number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'insurer_id' => $this->insurer_id,
            'insurer_name' => $this->whenLoaded('insurer', fn () => $this->insurer->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'renewed_from_policy_id' => $this->renewed_from_policy_id,
            'renewed_from_number' => $this->whenLoaded('renewedFrom', fn () => $this->renewedFrom?->policy_number),
            'renewal_number' => $this->whenLoaded('renewal', fn () => $this->renewal?->policy_number),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'currency_code' => $this->currency_code,
            'payment_method' => $this->payment_method,
            'net_premium' => $this->net_premium,
            'premium_tax_amount' => $this->premium_tax_amount,
            'gross_premium' => $this->gross_premium,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'commission_tax_amount' => $this->commission_tax_amount,
            'net_due_to_insurer' => number_format($this->netDueToInsurer(), 2, '.', ''),
            'premium_collected' => $this->premium_collected,
            'insurer_settled' => $this->insurer_settled,
            'premium_balance_due' => number_format($this->premiumBalanceDue(), 2, '.', ''),
            'insurer_balance_due' => number_format($this->insurerBalanceDue(), 2, '.', ''),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'fiscal_period_id' => $this->fiscal_period_id,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'installment_count' => $this->whenLoaded('installments', fn () => $this->installments->count()),
            'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn ($i) => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'due_date' => $i->due_date->toDateString(),
                'amount' => $i->amount,
                'amount_collected' => $i->amount_collected,
                'status' => $i->status,
            ])->values()),
            'endorsements' => PolicyEndorsementResource::collection($this->whenLoaded('endorsements')),
            'endorsements_count' => $this->whenCounted('endorsements'),
            'cancellations' => PolicyCancellationResource::collection($this->whenLoaded('cancellations')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
