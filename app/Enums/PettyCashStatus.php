<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P6 — Petty cash voucher lifecycle: draft → posted (or cancelled).
 */
enum PettyCashStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
