<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PettyCashStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * On-account customer receipt (advance/deposit).
 *
 * @property PettyCashStatus $status
 */
final class CustomerAdvance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'advance_number', 'customer_id', 'receipt_date', 'bank_account_id', 'advance_account_id',
        'payment_method', 'reference', 'currency_code', 'amount', 'applied_amount',
        'status', 'fiscal_period_id', 'batch_number', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => PettyCashStatus::class, // draft / posted / cancelled
        'receipt_date' => 'date',
        'amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, CustomerAdvance> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Amount still available to apply. */
    public function unapplied(): float
    {
        return round((float) $this->amount - (float) $this->applied_amount, 2);
    }
}
