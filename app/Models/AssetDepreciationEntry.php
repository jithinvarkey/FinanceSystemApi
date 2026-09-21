<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P7 — One month's depreciation charge for an asset (audit of the run).
 */
final class AssetDepreciationEntry extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'period_year', 'period_month', 'entry_date', 'amount', 'batch_number', 'posted_by',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<FixedAsset, AssetDepreciationEntry> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
