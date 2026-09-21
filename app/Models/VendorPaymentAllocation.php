<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.12 — How much of a payment settles a specific invoice.
 */
final class VendorPaymentAllocation extends Model
{
    protected $fillable = ['vendor_payment_id', 'vendor_invoice_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<VendorInvoice, VendorPaymentAllocation> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }
}
