<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * N1 — one scheduled premium installment.
 */
final class PremiumInstallment extends Model
{
    protected $fillable = ['plan_id', 'policy_id', 'installment_no', 'due_date', 'amount', 'amount_paid', 'status'];

    protected $casts = ['due_date' => 'date', 'amount' => 'decimal:2', 'amount_paid' => 'decimal:2'];

    /** @return BelongsTo<PremiumInstallmentPlan, PremiumInstallment> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumInstallmentPlan::class, 'plan_id');
    }

    /** Outstanding balance on this installment. */
    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->amount_paid, 2);
    }
}
