<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\CancellationMethod;
use App\Enums\CancellationStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 Slice C (§10) — A policy cancellation: earned/unearned split + refund.
 *
 * @property CancellationStatus $status
 * @property CancellationMethod $method
 */
final class PolicyCancellation extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'cancellation_number', 'policy_id', 'method', 'cancellation_date', 'reason',
        'policy_days', 'days_on_risk', 'short_rate_penalty',
        'refund_net', 'refund_premium_tax', 'refund_gross', 'clawback_commission', 'clawback_commission_tax',
        'status', 'fiscal_period_id', 'batch_number', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => CancellationStatus::class,
        'method' => CancellationMethod::class,
        'cancellation_date' => 'date',
        'policy_days' => 'integer',
        'days_on_risk' => 'integer',
        'short_rate_penalty' => 'decimal:2',
        'refund_net' => 'decimal:2',
        'refund_premium_tax' => 'decimal:2',
        'refund_gross' => 'decimal:2',
        'clawback_commission' => 'decimal:2',
        'clawback_commission_tax' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return BelongsTo<Policy, PolicyCancellation> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => CancellationStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => CancellationStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
