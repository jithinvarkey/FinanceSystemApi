<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\FinanceRuleException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * GL Posting Core — one immutable ledger line.
 * Never updated after creation; corrections happen by reversal batches.
 *
 * @property string $batch_number
 * @property string $debit
 * @property string $credit
 */
final class GlTransaction extends Model
{
    use HasFactory;

    /**
     * Enforce append-only semantics: a posted ledger row can never be
     * mutated or deleted. Corrections must go through a reversal batch.
     */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new FinanceRuleException('Ledger rows are immutable — post a reversal batch instead of editing.');
        });

        static::deleting(static function (): void {
            throw new FinanceRuleException('Ledger rows are immutable — post a reversal batch instead of deleting.');
        });
    }

    protected $fillable = [
        'batch_number', 'fiscal_period_id', 'transaction_date', 'account_id',
        'cost_center_id', 'dimension_id', 'debit', 'credit', 'currency_code', 'exchange_rate',
        'base_debit', 'base_credit', 'source_type', 'source_id', 'description',
        'is_reversal', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'base_debit' => 'decimal:2',
        'base_credit' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'is_reversal' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
