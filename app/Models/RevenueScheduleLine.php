<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E6 — one monthly slice of a revenue schedule.
 */
final class RevenueScheduleLine extends Model
{
    protected $fillable = ['revenue_schedule_id', 'period_date', 'amount', 'recognized', 'batch_number'];

    protected $casts = ['period_date' => 'date', 'amount' => 'decimal:2', 'recognized' => 'boolean'];

    /** @return BelongsTo<RevenueSchedule, RevenueScheduleLine> */
    public function revenueSchedule(): BelongsTo
    {
        return $this->belongsTo(RevenueSchedule::class);
    }
}
