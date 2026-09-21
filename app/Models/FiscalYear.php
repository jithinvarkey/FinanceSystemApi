<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FIN-0002 — A fiscal year owning 12 (or 13) fiscal periods.
 *
 * @property int $id
 * @property string $code
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property string $status
 */
final class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'start_date', 'end_date', 'status', 'created_by'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('period_number');
    }
}
