<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 — Insurance product under a line of business. Carries the default
 * commission rate, the VAT tax code (null/EXEMPT for life), and the
 * commission-revenue GL account.
 */
final class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'lob_id', 'code', 'name', 'default_commission_rate',
        'default_tax_code_id', 'commission_revenue_account_id', 'status',
    ];

    protected $casts = [
        'default_commission_rate' => 'decimal:4',
    ];

    /** @return BelongsTo<LineOfBusiness, Product> */
    public function lob(): BelongsTo
    {
        return $this->belongsTo(LineOfBusiness::class, 'lob_id');
    }

    /** @return BelongsTo<TaxCode, Product> */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }

    /** @return BelongsTo<ChartOfAccount, Product> */
    public function commissionRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'commission_revenue_account_id');
    }
}
