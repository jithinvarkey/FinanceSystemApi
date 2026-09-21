<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * N2 — one line of a purchase order.
 */
final class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'description', 'quantity', 'unit_price', 'line_total', 'received_qty'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'received_qty' => 'decimal:2'];

    /** @return BelongsTo<PurchaseOrder, PurchaseOrderLine> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function outstandingQty(): float
    {
        return round((float) $this->quantity - (float) $this->received_qty, 2);
    }
}
