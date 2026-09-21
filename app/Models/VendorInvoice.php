<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\VendorInvoiceStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P3.6–P3.10 — Vendor (purchase) invoice.
 *
 * @property VendorInvoiceStatus $status
 */
final class VendorInvoice extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'invoice_number', 'vendor_invoice_no', 'vendor_id', 'purchase_order_id', 'invoice_date', 'due_date',
        'reference', 'description', 'currency_code', 'exchange_rate',
        'subtotal', 'tax_amount', 'total_amount', 'amount_paid', 'withholding_amount', 'reverse_charge', 'status', 'is_opening', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => VendorInvoiceStatus::class,
        'is_opening' => 'boolean',
        'reverse_charge' => 'boolean',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function balanceDue(): float
    {
        return round((float) $this->total_amount - (float) $this->amount_paid, 2);
    }

    /** Posted and still owing — eligible for payment. */
    public function isPayable(): bool
    {
        return $this->status === VendorInvoiceStatus::Posted && $this->balanceDue() > 0;
    }

    /** @return HasMany<VendorInvoiceLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Vendor, VendorInvoice> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => VendorInvoiceStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => VendorInvoiceStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
