<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P5 — A completed bank reconciliation of a bank GL account to a statement.
 */
final class BankReconciliation extends Model
{
    protected $fillable = [
        'bank_account_id', 'statement_date', 'statement_balance', 'opening_balance',
        'cleared_total', 'difference', 'status', 'created_by', 'completed_at',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'cleared_total' => 'decimal:2',
        'difference' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    /** @return HasMany<BankReconciliationLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    /** @return BelongsTo<ChartOfAccount, BankReconciliation> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }
}
