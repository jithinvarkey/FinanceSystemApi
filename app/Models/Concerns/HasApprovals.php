<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * P0.4 — Mix into any document that routes through the approval engine
 * (vendor invoices, payments, expense claims, budgets, journals…).
 */
trait HasApprovals
{
    /** @return MorphMany<ApprovalRequest> */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable')->latest('id');
    }

    /** The current/most-recent approval request for this document. */
    public function currentApproval(): ?ApprovalRequest
    {
        return $this->approvalRequests()->first();
    }
}
