<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vendor
 */
final class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_code' => $this->vendor_code,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'vendor_type' => $this->vendor_type->value,
            'trn' => $this->trn,
            'trade_license_no' => $this->trade_license_no,
            'trade_license_expiry' => $this->trade_license_expiry?->toDateString(),
            'licence_expired' => $this->hasExpiredLicence(),
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days,
            'currency_code' => $this->currency_code,
            'default_payable_account_id' => $this->default_payable_account_id,
            'default_expense_account_id' => $this->default_expense_account_id,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'is_blocked' => $this->is_blocked,
            'block_reason' => $this->block_reason,
            'is_transactable' => $this->isTransactable(),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'bank_accounts' => VendorBankAccountResource::collection($this->whenLoaded('bankAccounts')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
