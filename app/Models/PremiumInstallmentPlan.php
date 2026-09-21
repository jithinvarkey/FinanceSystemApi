<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * N1 — a premium installment plan for a policy.
 */
final class PremiumInstallmentPlan extends Model
{
    protected $fillable = ['policy_id', 'installments', 'frequency', 'start_date', 'total_amount', 'status', 'created_by'];

    protected $casts = ['start_date' => 'date', 'total_amount' => 'decimal:2'];

    /** @return HasMany<PremiumInstallment> */
    public function items(): HasMany
    {
        return $this->hasMany(PremiumInstallment::class, 'plan_id')->orderBy('installment_no');
    }

    /** @return BelongsTo<Policy, PremiumInstallmentPlan> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
