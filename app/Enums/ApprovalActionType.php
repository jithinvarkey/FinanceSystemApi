<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P0.4 — A single approver decision recorded against a step.
 */
enum ApprovalActionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
