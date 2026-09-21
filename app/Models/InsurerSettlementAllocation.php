<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.17 Slice B — How much of an insurer settlement remits a specific policy's
 * net premium.
 */
final class InsurerSettlementAllocation extends Model
{
    protected $fillable = ['insurer_settlement_id', 'policy_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<Policy, InsurerSettlementAllocation> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
