<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P0.5 — A gap-free counter for one (document type, branch, year).
 *
 * @property string $prefix
 * @property int $padding
 * @property bool $include_year
 * @property string $separator
 * @property int $period_year
 * @property int $next_number
 */
final class NumberSequence extends Model
{
    protected $fillable = [
        'document_type', 'branch_id', 'period_year',
        'prefix', 'padding', 'include_year', 'separator', 'next_number',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'period_year' => 'integer',
        'padding' => 'integer',
        'include_year' => 'boolean',
        'next_number' => 'integer',
    ];
}
