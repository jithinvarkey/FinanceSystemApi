<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * P0.4 — A workflow instance running against one document.
 *
 * @property ApprovalStatus $status
 * @property int|null $current_sequence
 * @property string $amount
 */
final class ApprovalRequest extends Model
{
    protected $fillable = [
        'approvable_type', 'approvable_id', 'approval_workflow_id', 'document_type',
        'amount', 'status', 'current_sequence', 'requested_by', 'decided_at',
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'amount' => 'decimal:2',
        'current_sequence' => 'integer',
        'decided_at' => 'datetime',
    ];

    /** @return MorphTo<Model, ApprovalRequest> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<ApprovalWorkflow, ApprovalRequest> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /** @return BelongsTo<User, ApprovalRequest> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ApprovalAction> */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('sequence');
    }
}
