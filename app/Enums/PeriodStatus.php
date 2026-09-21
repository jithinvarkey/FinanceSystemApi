<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a fiscal period. Closed periods reject all postings;
 * soft-closed periods accept adjustment journals only.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case SoftClosed = 'soft_closed';
    case Closed = 'closed';

    public function acceptsPostings(): bool
    {
        return $this !== self::Closed;
    }
}
