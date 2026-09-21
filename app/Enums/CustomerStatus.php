<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.1 — Customer master lifecycle.
 * draft -> pending_approval -> active (or rejected); active <-> inactive.
 */
enum CustomerStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Inactive = 'inactive';
    case Rejected = 'rejected';

    /** Editable only before it enters the approval queue. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** Can be sent for approval. */
    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }
}
