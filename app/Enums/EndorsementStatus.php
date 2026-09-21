<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.17 — Endorsement lifecycle.
 * draft -> pending_approval -> approved -> posted (or rejected).
 */
enum EndorsementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }
}
