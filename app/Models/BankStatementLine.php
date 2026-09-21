<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * F20 — one imported bank-statement line.
 */
final class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_account_id', 'txn_date', 'description', 'reference',
        'amount', 'balance', 'matched_gl_transaction_id', 'created_by',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];
}
