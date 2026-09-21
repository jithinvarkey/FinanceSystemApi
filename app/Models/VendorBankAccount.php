<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.4 — A vendor's beneficiary bank account. Edits flip is_verified off so a
 * checker must re-verify before the account is used for payment.
 */
final class VendorBankAccount extends Model
{
    protected $fillable = [
        'vendor_id', 'bank_name', 'account_name', 'account_number',
        'iban', 'swift_bic', 'currency_code', 'is_primary', 'is_verified',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
    ];

    /** @return BelongsTo<Vendor, VendorBankAccount> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
