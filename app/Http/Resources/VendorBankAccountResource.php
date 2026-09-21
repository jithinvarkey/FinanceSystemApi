<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VendorBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorBankAccount
 */
final class VendorBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'currency_code' => $this->currency_code,
            'is_primary' => $this->is_primary,
            'is_verified' => $this->is_verified,
        ];
    }
}
