<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ExpenseClaim
 */
final class ExpenseClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_number' => $this->claim_number,
            'claimant' => $this->claimant,
            'expense_date' => $this->expense_date?->toDateString(),
            'credit_account_id' => $this->credit_account_id,
            'credit_account_name' => $this->whenLoaded('creditAccount', fn () => $this->creditAccount->name),
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'description' => $this->description,
            'currency_code' => $this->currency_code,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'batch_number' => $this->batch_number,
            'fiscal_period_id' => $this->fiscal_period_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => ExpenseClaimLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
