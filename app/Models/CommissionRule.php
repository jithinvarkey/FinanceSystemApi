<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E5 — a commission rule (flat / tiered / renewal), scoped by insurer/product.
 */
final class CommissionRule extends Model
{
    protected $fillable = ['name', 'insurer_id', 'product_id', 'rule_type', 'rate', 'is_active', 'created_by'];

    protected $casts = ['rate' => 'decimal:4', 'is_active' => 'boolean'];

    /** @return HasMany<CommissionRuleTier> */
    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionRuleTier::class)->orderBy('min_premium');
    }
}
