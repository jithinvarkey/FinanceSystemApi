<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E14 — one monthly row of an IFRS 16 lease amortisation schedule.
 */
final class LeaseScheduleLine extends Model
{
    protected $fillable = [
        'lease_id', 'period_date', 'opening_liability', 'payment', 'interest', 'principal',
        'closing_liability', 'rou_depreciation', 'recognized', 'batch_number',
    ];

    protected $casts = [
        'period_date' => 'date', 'opening_liability' => 'decimal:2', 'payment' => 'decimal:2',
        'interest' => 'decimal:2', 'principal' => 'decimal:2', 'closing_liability' => 'decimal:2',
        'rou_depreciation' => 'decimal:2', 'recognized' => 'boolean',
    ];

    /** @return BelongsTo<Lease, LeaseScheduleLine> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
