<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIN-0036 — One side of a double entry within a journal.
 */
final class JournalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id', 'line_no', 'account_id', 'cost_center_id', 'dimension_id',
        'description', 'debit', 'credit', 'currency_code', 'exchange_rate',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
