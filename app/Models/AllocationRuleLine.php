<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F26 — one target of an allocation rule (a cost centre + its share %).
 */
final class AllocationRuleLine extends Model
{
    protected $fillable = ['allocation_rule_id', 'cost_center_id', 'percentage'];

    protected $casts = ['percentage' => 'decimal:4'];

    /** @return BelongsTo<AllocationRule, AllocationRuleLine> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AllocationRule::class, 'allocation_rule_id');
    }
}
