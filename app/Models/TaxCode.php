<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FIN-0004 — VAT / tax rule with GL account mapping.
 *
 * @property TaxType $tax_type
 * @property string $rate Decimal percentage
 */
final class TaxCode extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'tax_type', 'rate',
        'input_account_id', 'output_account_id', 'is_recoverable', 'status',
    ];

    protected $casts = [
        'tax_type' => TaxType::class,
        'rate' => 'decimal:4',
        'is_recoverable' => 'boolean',
        'status' => RecordStatus::class,
    ];

    public function inputAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'input_account_id');
    }

    public function outputAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'output_account_id');
    }

    /**
     * Tax amount for a given net amount, rounded to 2 dp.
     */
    public function calculate(float $netAmount): float
    {
        return round($netAmount * ((float) $this->rate / 100), 2);
    }
}
