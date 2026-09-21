<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FIN-0003 — Currency master. Exactly one row carries is_base = true.
 *
 * @property int $id
 * @property string $code
 * @property bool $is_base
 */
final class Currency extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'symbol', 'decimal_places', 'is_base', 'status'];

    protected $casts = [
        'is_base' => 'boolean',
        'decimal_places' => 'integer',
        'status' => RecordStatus::class,
    ];

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->orderByDesc('rate_date');
    }
}
