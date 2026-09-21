<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * N2 — a purchase order (a draft PO is the requisition).
 */
final class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'vendor_id', 'order_date', 'expected_date', 'total_amount',
        'status', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = ['order_date' => 'date', 'expected_date' => 'date', 'total_amount' => 'decimal:2', 'approved_at' => 'datetime'];

    /** @return HasMany<PurchaseOrderLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return HasMany<GoodsReceipt> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return BelongsTo<Vendor, PurchaseOrder> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
