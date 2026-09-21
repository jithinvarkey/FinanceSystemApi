<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.17 Slice B — How much of a premium collection settles a specific
 * policy installment.
 */
final class PremiumCollectionAllocation extends Model
{
    protected $fillable = ['premium_collection_id', 'policy_installment_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<PolicyInstallment, PremiumCollectionAllocation> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(PolicyInstallment::class, 'policy_installment_id');
    }
}
