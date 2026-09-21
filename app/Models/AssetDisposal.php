<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F23 — record of an asset disposal (sale or scrap) and its gain/loss.
 */
final class AssetDisposal extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'disposal_date', 'proceeds', 'book_value', 'gain_loss', 'method', 'batch_number', 'created_by',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'proceeds' => 'decimal:2',
        'book_value' => 'decimal:2',
        'gain_loss' => 'decimal:2',
    ];

    /** @return BelongsTo<FixedAsset, AssetDisposal> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
