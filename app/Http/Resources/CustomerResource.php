<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'customer_type' => $this->customer_type->value,
            'trn' => $this->trn,
            'commercial_reg_no' => $this->commercial_reg_no,
            'commercial_reg_expiry' => $this->commercial_reg_expiry?->toDateString(),
            'registration_expired' => $this->hasExpiredRegistration(),
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days,
            'currency_code' => $this->currency_code,
            'credit_limit' => $this->credit_limit,
            'default_receivable_account_id' => $this->default_receivable_account_id,
            'default_revenue_account_id' => $this->default_revenue_account_id,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'is_blocked' => $this->is_blocked,
            'block_reason' => $this->block_reason,
            'is_transactable' => $this->isTransactable(),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'bank_accounts' => CustomerBankAccountResource::collection($this->whenLoaded('bankAccounts')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
