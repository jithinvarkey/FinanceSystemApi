<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P5 — A GL transaction cleared by a bank reconciliation.
 */
final class BankReconciliationLine extends Model
{
    protected $fillable = ['bank_reconciliation_id', 'gl_transaction_id'];

    /** @return BelongsTo<GlTransaction, BankReconciliationLine> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(GlTransaction::class, 'gl_transaction_id');
    }
}
