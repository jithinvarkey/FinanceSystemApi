<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ApprovalRequest;

/**
 * P0.4 — Implemented by documents that react when their approval resolves
 * (e.g. a vendor becomes active on approval, rejected on rejection).
 * The ApprovalService invokes these after the request reaches a terminal state.
 */
interface Approvable
{
    public function onApprovalApproved(ApprovalRequest $request): void;

    public function onApprovalRejected(ApprovalRequest $request): void;
}
