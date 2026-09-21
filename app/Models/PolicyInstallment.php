<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.17 — One installment of a policy's gross premium.
 */
final class PolicyInstallment extends Model
{
    protected $fillable = ['policy_id', 'sequence', 'due_date', 'amount', 'amount_collected', 'status'];

    protected $casts = [
        'sequence' => 'integer',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'amount_collected' => 'decimal:2',
    ];

    public function balanceDue(): float
    {
        return round((float) $this->amount - (float) $this->amount_collected, 2);
    }

    /** @return BelongsTo<Policy, PolicyInstallment> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
