<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P8 — An annual budget target for one GL account.
 */
final class BudgetLine extends Model
{
    protected $fillable = ['budget_id', 'account_id', 'annual_amount'];

    protected $casts = ['annual_amount' => 'decimal:2'];

    /** @return BelongsTo<ChartOfAccount, BudgetLine> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
