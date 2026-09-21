<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\VendorStatus;
use App\Enums\VendorType;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P3.1–P3.5 — Vendor master.
 *
 * @property VendorStatus $status
 * @property VendorType $vendor_type
 * @property bool $is_blocked
 */
final class Vendor extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'vendor_code', 'name', 'trade_name', 'vendor_type', 'trn',
        'trade_license_no', 'trade_license_expiry', 'contact_person', 'email',
        'phone', 'address', 'payment_terms_days', 'settlement_discount_percent', 'settlement_discount_days', 'wht_rate', 'currency_code',
        'default_payable_account_id', 'default_expense_account_id',
        'status', 'rejection_reason', 'is_blocked', 'block_reason',
        'blocked_by', 'blocked_at', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'vendor_type' => VendorType::class,
        'status' => VendorStatus::class,
        'trade_license_expiry' => 'date',
        'payment_terms_days' => 'integer',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** @return HasMany<VendorBankAccount> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(VendorBankAccount::class);
    }

    /** @return BelongsTo<ChartOfAccount, Vendor> */
    public function defaultPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_payable_account_id');
    }

    /** Vendors whose trade licence expires within the next $days. */
    public function scopeLicenceExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('trade_license_expiry')
            ->whereBetween('trade_license_expiry', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function hasExpiredLicence(): bool
    {
        return $this->trade_license_expiry !== null && $this->trade_license_expiry->isPast();
    }

    /** May this vendor receive invoices/payments right now? */
    public function isTransactable(): bool
    {
        return $this->status === VendorStatus::Active && ! $this->is_blocked;
    }

    // ----- Approvable (reacts to the P0.4 engine) -----

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $lastApproval = $request->actions()
            ->where('action', ApprovalActionType::Approved->value)
            ->latest('id')
            ->first();

        $this->forceFill([
            'status' => VendorStatus::Active,
            'approved_by' => $lastApproval?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()
            ->where('action', ApprovalActionType::Rejected->value)
            ->latest('id')
            ->first();

        $this->forceFill([
            'status' => VendorStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
