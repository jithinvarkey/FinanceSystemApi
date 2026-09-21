<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PremiumCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PremiumCollection
 */
final class PremiumCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection_number' => $this->collection_number,
            'policy_id' => $this->policy_id,
            'policy_number' => $this->whenLoaded('policy', fn () => $this->policy->policy_number),
            'customer_name' => $this->whenLoaded('policy', fn () => $this->policy->customer?->name),
            'collection_date' => $this->collection_date?->toDateString(),
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
                'policy_installment_id' => $a->policy_installment_id,
                'sequence' => $a->installment?->sequence,
                'amount' => $a->amount,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
