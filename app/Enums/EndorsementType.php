<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.17 — The four financial endorsement types. Addition & upgrade raise an
 * additional premium; deletion & downgrade raise a refund.
 */
enum EndorsementType: string
{
    case Addition = 'addition';
    case Deletion = 'deletion';
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';

    /** Additional-premium endorsements (vs refund). */
    public function isAdditional(): bool
    {
        return in_array($this, [self::Addition, self::Upgrade], true);
    }

    public function direction(): string
    {
        return $this->isAdditional() ? 'additional' : 'refund';
    }
}
