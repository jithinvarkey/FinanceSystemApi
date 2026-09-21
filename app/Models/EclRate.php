<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E14 — IFRS 9 ECL loss rate for one AR aging bucket.
 */
final class EclRate extends Model
{
    public $timestamps = true;

    protected $fillable = ['bucket', 'loss_rate'];

    protected $casts = ['loss_rate' => 'decimal:3'];
}
