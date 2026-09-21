<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.6 — One line of a customer invoice (net revenue amount + its VAT).
 */
final class CustomerInvoiceLine extends Model
{
    protected $fillable = [
        'customer_invoice_id', 'line_no', 'account_id', 'cost_center_id', 'description',
        'amount', 'tax_code_id', 'tax_rate', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /** @return BelongsTo<ChartOfAccount, CustomerInvoiceLine> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
