<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0.4 — One level in a workflow: a permission to hold and an amount threshold.
 *
 * @property int $sequence
 * @property string $required_permission
 * @property string $min_amount
 */
final class ApprovalStep extends Model
{
    protected $fillable = [
        'approval_workflow_id', 'sequence', 'name', 'required_permission', 'min_amount', 'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'min_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<ApprovalWorkflow, ApprovalStep> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }
}
