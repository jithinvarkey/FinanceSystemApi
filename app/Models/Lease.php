<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E14 — IFRS 16 lease (right-of-use asset + lease liability).
 */
final class Lease extends Model
{
    protected $fillable = [
        'reference', 'description', 'lessor', 'start_date', 'end_date', 'monthly_payment', 'discount_rate',
        'initial_liability', 'rou_asset_account_id', 'lease_liability_account_id', 'interest_expense_account_id',
        'depreciation_expense_account_id', 'bank_account_id', 'status', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date',
        'monthly_payment' => 'decimal:2', 'discount_rate' => 'decimal:3', 'initial_liability' => 'decimal:2',
    ];

    /** @return HasMany<LeaseScheduleLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(LeaseScheduleLine::class)->orderBy('period_date');
    }
}
