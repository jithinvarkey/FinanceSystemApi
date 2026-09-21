<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyCancellation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyCancellation
 */
final class PolicyCancellationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cancellation_number' => $this->cancellation_number,
            'policy_id' => $this->policy_id,
            'method' => $this->method->value,
            'cancellation_date' => $this->cancellation_date?->toDateString(),
            'reason' => $this->reason,
            'policy_days' => $this->policy_days,
            'days_on_risk' => $this->days_on_risk,
            'short_rate_penalty' => $this->short_rate_penalty,
            'refund_net' => $this->refund_net,
            'refund_premium_tax' => $this->refund_premium_tax,
            'refund_gross' => $this->refund_gross,
            'clawback_commission' => $this->clawback_commission,
            'clawback_commission_tax' => $this->clawback_commission_tax,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
