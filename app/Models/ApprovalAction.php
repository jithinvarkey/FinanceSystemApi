<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0.4 — Immutable record of one approver decision on a step.
 *
 * @property ApprovalActionType $action
 */
final class ApprovalAction extends Model
{
    protected $fillable = [
        'approval_request_id', 'approval_step_id', 'sequence', 'action', 'actor_id', 'comment', 'acted_at',
    ];

    protected $casts = [
        'action' => ApprovalActionType::class,
        'sequence' => 'integer',
        'acted_at' => 'datetime',
    ];

    /** @return BelongsTo<ApprovalRequest, ApprovalAction> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return BelongsTo<User, ApprovalAction> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
