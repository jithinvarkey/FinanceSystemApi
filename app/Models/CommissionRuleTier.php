<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E5 — one premium band of a tiered commission rule.
 */
final class CommissionRuleTier extends Model
{
    protected $fillable = ['commission_rule_id', 'min_premium', 'rate'];

    protected $casts = ['min_premium' => 'decimal:2', 'rate' => 'decimal:4'];
}
