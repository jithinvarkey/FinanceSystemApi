<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E4 — a record of a dunning reminder sent for an overdue invoice.
 */
final class DunningLog extends Model
{
    protected $fillable = [
        'customer_id', 'customer_invoice_id', 'level', 'channel', 'balance', 'sent_to', 'sent_by',
    ];

    protected $casts = ['level' => 'integer', 'balance' => 'decimal:2'];
}
