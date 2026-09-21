<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIN-0039 — Template line copied into each generated journal entry.
 */
final class RecurringJournalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_journal_id', 'line_no', 'account_id', 'cost_center_id',
        'description', 'debit', 'credit',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function recurringJournal(): BelongsTo
    {
        return $this->belongsTo(RecurringJournal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
