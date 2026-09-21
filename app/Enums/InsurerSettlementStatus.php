<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.17 Slice B — Insurer settlement lifecycle (mirrors VendorPaymentStatus).
 */
enum InsurerSettlementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }
}
