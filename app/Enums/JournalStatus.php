<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Journal entry lifecycle. Transitions are enforced by JournalService:
 * draft -> submitted -> approved -> posted; submitted -> rejected -> (edit) draft;
 * posted -> reversed.
 */
enum JournalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Reversed = 'reversed';

    /** Statuses in which the document may still be edited or deleted. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** Allowed next states from the current one. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [self::Posted],
            self::Rejected => [self::Submitted],
            self::Posted => [self::Reversed],
            self::Reversed => [],
        };
    }
}
