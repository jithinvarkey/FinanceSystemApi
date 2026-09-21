<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\VendorPaymentStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P3.11–P3.15 — Vendor payment settling posted invoices.
 *
 * @property VendorPaymentStatus $status
 */
final class VendorPayment extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'payment_number', 'vendor_id', 'payment_date', 'bank_account_id', 'payment_method',
        'reference', 'currency_code', 'exchange_rate', 'amount', 'status', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => VendorPaymentStatus::class,
        'payment_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<VendorPaymentAllocation> */
    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    /** @return BelongsTo<Vendor, VendorPayment> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<ChartOfAccount, VendorPayment> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => VendorPaymentStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => VendorPaymentStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
