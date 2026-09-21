<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W8 — a workforce budget line (one category per year).
 */
final class WorkforceBudget extends Model
{
    protected $fillable = ['year', 'category', 'amount'];

    protected $casts = ['year' => 'integer', 'amount' => 'decimal:2'];
}
