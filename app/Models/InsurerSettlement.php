<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\InsurerSettlementStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 Slice B (§8.4) — Net premium remitted to an insurer across policies.
 *
 * @property InsurerSettlementStatus $status
 */
final class InsurerSettlement extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'settlement_number', 'insurer_id', 'settlement_date', 'bank_account_id', 'payment_method',
        'reference', 'currency_code', 'exchange_rate', 'amount', 'status', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => InsurerSettlementStatus::class,
        'settlement_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<InsurerSettlementAllocation> */
    public function allocations(): HasMany
    {
        return $this->hasMany(InsurerSettlementAllocation::class);
    }

    /** @return BelongsTo<Vendor, InsurerSettlement> */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'insurer_id');
    }

    /** @return BelongsTo<ChartOfAccount, InsurerSettlement> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_account_id');
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => InsurerSettlementStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => InsurerSettlementStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
