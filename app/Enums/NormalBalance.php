<?php

declare(strict_types=1);

namespace App\Enums;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
