<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * F15 — VAT return filing lifecycle: draft (figures snapshotted, still editable)
 * → filed (submitted to ZATCA, period locked) → paid (net settled to ZATCA).
 */
enum VatReturnStatus: string
{
    case Draft = 'draft';
    case Filed = 'filed';
    case Paid = 'paid';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Filed/paid returns lock their tax period against further postings. */
    public function locksPeriod(): bool
    {
        return $this !== self::Draft;
    }
}
