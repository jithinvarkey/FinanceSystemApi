<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F26 — an allocation rule: source → targets by percentage.
 */
final class AllocationRule extends Model
{
    protected $fillable = [
        'name', 'source_account_id', 'target_account_id', 'source_cost_center_id', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** @return HasMany<AllocationRuleLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(AllocationRuleLine::class);
    }
}
