<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E6 — a revenue recognition schedule.
 */
final class RevenueSchedule extends Model
{
    protected $fillable = [
        'reference', 'name', 'start_date', 'end_date', 'total_amount', 'recognized_amount',
        'deferred_account_id', 'revenue_account_id', 'status', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date',
        'total_amount' => 'decimal:2', 'recognized_amount' => 'decimal:2',
    ];

    /** @return HasMany<RevenueScheduleLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(RevenueScheduleLine::class)->orderBy('period_date');
    }
}
