<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyEndorsement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyEndorsement
 */
final class PolicyEndorsementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'endorsement_number' => $this->endorsement_number,
            'policy_id' => $this->policy_id,
            'type' => $this->type->value,
            'direction' => $this->direction,
            'effective_date' => $this->effective_date?->toDateString(),
            'reason' => $this->reason,
            'delta_net_premium' => $this->delta_net_premium,
            'delta_premium_tax' => $this->delta_premium_tax,
            'delta_gross' => $this->delta_gross,
            'delta_commission' => $this->delta_commission,
            'delta_commission_tax' => $this->delta_commission_tax,
            'signed_gross' => number_format($this->signedGross(), 2, '.', ''),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
