<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIN-0002 / FIN-0040 — A posting period inside a fiscal year.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_number
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property PeriodStatus $status
 */
final class FiscalPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year_id', 'period_number', 'name', 'start_date', 'end_date',
        'status', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'status' => PeriodStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'period_number' => 'integer',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Scope: the period containing a given date.
     */
    public function scopeContaining($query, \DateTimeInterface $date)
    {
        return $query->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date);
    }
}
