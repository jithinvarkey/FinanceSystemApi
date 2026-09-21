<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * FIN-0036..0038 — Manual journal entry header.
 *
 * @property JournalStatus $status
 * @property string $journal_number
 */
final class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_number', 'journal_date', 'reference', 'description', 'status',
        'currency_code', 'total_debit', 'total_credit', 'fiscal_period_id',
        'recurring_journal_id', 'reversal_of_id', 'is_opening', 'created_by',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'rejection_reason', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'status' => JournalStatus::class,
        'is_opening' => 'boolean',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function recurringJournal(): BelongsTo
    {
        return $this->belongsTo(RecurringJournal::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** Ledger rows produced when this journal posts. */
    public function glTransactions(): MorphMany
    {
        return $this->morphMany(GlTransaction::class, 'source');
    }
}
