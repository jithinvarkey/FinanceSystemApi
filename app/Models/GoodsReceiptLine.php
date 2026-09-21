<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * N2 — a received quantity for one PO line within a goods receipt.
 */
final class GoodsReceiptLine extends Model
{
    protected $fillable = ['goods_receipt_id', 'purchase_order_line_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:2'];
}
