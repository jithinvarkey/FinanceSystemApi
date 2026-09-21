<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Credit vs debit note. A credit note reduces the counterparty balance
 * (reverses revenue/expense); a debit note increases it.
 */
enum NoteType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
