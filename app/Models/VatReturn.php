<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VatReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F15 — A filed VAT return for a tax period (ZATCA).
 *
 * @property VatReturnStatus $status
 */
final class VatReturn extends Model
{
    protected $fillable = [
        'reference', 'period_from', 'period_to',
        'output_vat', 'input_vat', 'reverse_charge_base', 'reverse_charge_vat', 'net_vat_payable',
        'status', 'zatca_reference', 'notes',
        'filed_at', 'filed_by', 'paid_at', 'paid_by', 'payment_batch_number', 'created_by',
    ];

    protected $casts = [
        'status' => VatReturnStatus::class,
        'period_from' => 'date',
        'period_to' => 'date',
        'output_vat' => 'decimal:2',
        'input_vat' => 'decimal:2',
        'reverse_charge_base' => 'decimal:2',
        'reverse_charge_vat' => 'decimal:2',
        'net_vat_payable' => 'decimal:2',
        'filed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<User, VatReturn> */
    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
