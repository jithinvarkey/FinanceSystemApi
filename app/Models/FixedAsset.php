<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P7 — A depreciable fixed asset.
 *
 * @property AssetStatus $status
 */
final class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'asset_number', 'name', 'category', 'acquisition_date', 'cost', 'salvage_value',
        'useful_life_months', 'depreciation_method', 'is_cwip', 'accumulated_depreciation',
        'asset_account_id', 'accum_depreciation_account_id', 'depreciation_expense_account_id',
        'cost_center_id', 'location', 'custodian', 'status', 'created_by',
    ];

    protected $casts = [
        'status' => AssetStatus::class,
        'acquisition_date' => 'date',
        'cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'is_cwip' => 'boolean',
        'accumulated_depreciation' => 'decimal:2',
    ];

    /** @return HasMany<AssetDepreciationEntry> */
    public function entries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class)->orderBy('entry_date');
    }

    /** Net book value = cost − accumulated depreciation. */
    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    /** Total still to depreciate down to salvage. */
    public function depreciableRemaining(): float
    {
        return round((float) $this->cost - (float) $this->salvage_value - (float) $this->accumulated_depreciation, 2);
    }

    /** Straight-line charge per month. */
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return round(((float) $this->cost - (float) $this->salvage_value) / $this->useful_life_months, 2);
    }
}
