<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P8 — A budget for a fiscal year (annual targets per account).
 */
final class Budget extends Model
{
    protected $fillable = ['name', 'fiscal_year_id', 'status', 'created_by'];

    /** @return HasMany<BudgetLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /** @return BelongsTo<FiscalYear, Budget> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
