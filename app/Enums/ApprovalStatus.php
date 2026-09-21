<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P0.4 — Lifecycle of an approval request.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
