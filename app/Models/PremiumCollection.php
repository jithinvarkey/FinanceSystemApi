<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\PremiumCollectionStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 Slice B (§8.3) — Premium collected from the customer against a policy.
 *
 * @property PremiumCollectionStatus $status
 */
final class PremiumCollection extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'collection_number', 'policy_id', 'collection_date', 'bank_account_id', 'payment_method',
        'reference', 'currency_code', 'exchange_rate', 'amount', 'status', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => PremiumCollectionStatus::class,
        'collection_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<PremiumCollectionAllocation> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PremiumCollectionAllocation::class);
    }

    /** @return BelongsTo<Policy, PremiumCollection> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /** @return BelongsTo<ChartOfAccount, PremiumCollection> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => PremiumCollectionStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => PremiumCollectionStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
