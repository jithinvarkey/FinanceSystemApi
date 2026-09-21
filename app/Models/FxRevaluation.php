<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * F22 — a foreign-currency revaluation run.
 */
final class FxRevaluation extends Model
{
    protected $fillable = ['as_of', 'fx_account_id', 'net_adjustment', 'batch_number', 'created_by'];

    protected $casts = [
        'as_of' => 'date',
        'net_adjustment' => 'decimal:2',
    ];
}
