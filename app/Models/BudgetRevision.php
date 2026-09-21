<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E9 — an audit row for a budget line revision or transfer.
 */
final class BudgetRevision extends Model
{
    protected $fillable = ['budget_id', 'budget_line_id', 'type', 'counterpart_line_id', 'delta', 'new_amount', 'reason', 'created_by'];

    protected $casts = ['delta' => 'decimal:2', 'new_amount' => 'decimal:2'];

    /** @return BelongsTo<BudgetLine, BudgetRevision> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }
}
