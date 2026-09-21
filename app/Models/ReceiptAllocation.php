<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.12 — How much of a receipt settles a specific customer invoice.
 */
final class ReceiptAllocation extends Model
{
    protected $fillable = ['receipt_id', 'customer_invoice_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<CustomerInvoice, ReceiptAllocation> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
