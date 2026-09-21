<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\EndorsementStatus;
use App\Enums\EndorsementType;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 — A mid-term financial change to a policy.
 *
 * @property EndorsementType $type
 * @property EndorsementStatus $status
 */
final class PolicyEndorsement extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'endorsement_number', 'source_system', 'external_id', 'policy_id', 'type', 'direction', 'effective_date', 'reason',
        'delta_net_premium', 'delta_premium_tax', 'delta_gross', 'delta_commission', 'delta_commission_tax',
        'status', 'fiscal_period_id', 'batch_number', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'type' => EndorsementType::class,
        'status' => EndorsementStatus::class,
        'effective_date' => 'date',
        'delta_net_premium' => 'decimal:2',
        'delta_premium_tax' => 'decimal:2',
        'delta_gross' => 'decimal:2',
        'delta_commission' => 'decimal:2',
        'delta_commission_tax' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** Signed gross impact: additional positive, refund negative. */
    public function signedGross(): float
    {
        return round((float) $this->delta_gross * ($this->type->isAdditional() ? 1 : -1), 2);
    }

    /** @return BelongsTo<Policy, PolicyEndorsement> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => EndorsementStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => EndorsementStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
