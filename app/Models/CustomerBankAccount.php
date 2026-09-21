<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.4 — A customer's bank account (for refunds). Edits flip is_verified off so
 * a checker must re-verify before it is used.
 */
final class CustomerBankAccount extends Model
{
    protected $fillable = [
        'customer_id', 'bank_name', 'account_name', 'account_number',
        'iban', 'swift_bic', 'currency_code', 'is_primary', 'is_verified',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
    ];

    /** @return BelongsTo<Customer, CustomerBankAccount> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
