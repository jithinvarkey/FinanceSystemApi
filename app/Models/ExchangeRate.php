<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIN-0003 — Dated rate: units of base currency per 1 unit of foreign currency.
 */
final class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = ['currency_id', 'rate_date', 'rate', 'created_by'];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:8',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
