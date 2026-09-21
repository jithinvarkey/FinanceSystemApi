<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.1–P4.5 — Customer master.
 *
 * @property CustomerStatus $status
 * @property CustomerType $customer_type
 * @property bool $is_blocked
 */
final class Customer extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'customer_code', 'name', 'trade_name', 'customer_type', 'trn',
        'commercial_reg_no', 'commercial_reg_expiry', 'contact_person', 'email',
        'phone', 'address', 'payment_terms_days', 'currency_code', 'credit_limit',
        'default_receivable_account_id', 'default_revenue_account_id',
        'status', 'rejection_reason', 'is_blocked', 'block_reason',
        'blocked_by', 'blocked_at', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'customer_type' => CustomerType::class,
        'status' => CustomerStatus::class,
        'commercial_reg_expiry' => 'date',
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:2',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** @return HasMany<CustomerBankAccount> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CustomerBankAccount::class);
    }

    /** @return BelongsTo<ChartOfAccount, Customer> */
    public function defaultReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_receivable_account_id');
    }

    /** Customers whose commercial registration expires within the next $days. */
    public function scopeRegistrationExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('commercial_reg_expiry')
            ->whereBetween('commercial_reg_expiry', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function hasExpiredRegistration(): bool
    {
        return $this->commercial_reg_expiry !== null && $this->commercial_reg_expiry->isPast();
    }

    /** May this customer be invoiced right now? */
    public function isTransactable(): bool
    {
        return $this->status === CustomerStatus::Active && ! $this->is_blocked;
    }

    // ----- Approvable (reacts to the P0.4 engine) -----

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $lastApproval = $request->actions()
            ->where('action', ApprovalActionType::Approved->value)
            ->latest('id')
            ->first();

        $this->forceFill([
            'status' => CustomerStatus::Active,
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
            'status' => CustomerStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
