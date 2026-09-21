<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * N2 — a goods/service receipt against a purchase order.
 */
final class GoodsReceipt extends Model
{
    protected $fillable = ['gr_number', 'purchase_order_id', 'receipt_date', 'note', 'received_by'];

    protected $casts = ['receipt_date' => 'date'];

    /** @return HasMany<GoodsReceiptLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
