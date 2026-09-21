<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.17 — Policy lifecycle.
 * draft -> pending_approval -> approved -> issued (or rejected); issued -> cancelled/expired.
 */
enum PolicyStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
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
