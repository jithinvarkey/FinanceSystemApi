<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E7 — a planned cash movement layered onto the treasury forecast.
 */
final class TreasuryItem extends Model
{
    protected $fillable = ['description', 'direction', 'amount', 'expected_date', 'status', 'created_by'];

    protected $casts = ['expected_date' => 'date', 'amount' => 'decimal:2'];
}
