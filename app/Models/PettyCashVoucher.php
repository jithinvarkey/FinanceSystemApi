<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PettyCashStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P6 — A petty cash disbursement voucher.
 *
 * @property PettyCashStatus $status
 */
final class PettyCashVoucher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'voucher_number', 'voucher_date', 'petty_cash_account_id', 'expense_account_id', 'cost_center_id',
        'payee', 'description', 'reference', 'currency_code',
        'amount', 'tax_code_id', 'tax_rate', 'tax_amount', 'total_amount',
        'status', 'fiscal_period_id', 'batch_number', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => PettyCashStatus::class,
        'voucher_date' => 'date',
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    /** @return BelongsTo<ChartOfAccount, PettyCashVoucher> */
    public function pettyCashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'petty_cash_account_id');
    }

    /** @return BelongsTo<ChartOfAccount, PettyCashVoucher> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }
}
