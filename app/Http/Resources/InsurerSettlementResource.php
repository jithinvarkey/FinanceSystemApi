<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InsurerSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InsurerSettlement
 */
final class InsurerSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_number' => $this->settlement_number,
            'insurer_id' => $this->insurer_id,
            'insurer_name' => $this->whenLoaded('insurer', fn () => $this->insurer->name),
            'settlement_date' => $this->settlement_date?->toDateString(),
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
                'policy_id' => $a->policy_id,
                'policy_number' => $a->policy?->policy_number,
                'amount' => $a->amount,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
