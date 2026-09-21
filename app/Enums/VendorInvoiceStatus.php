<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P3.6–P3.10 — Vendor invoice lifecycle.
 * draft -> pending_approval -> approved -> posted (or rejected / cancelled).
 */
enum VendorInvoiceStatus: string
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
