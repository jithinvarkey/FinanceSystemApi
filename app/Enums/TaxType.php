<?php

declare(strict_types=1);

namespace App\Enums;

enum TaxType: string
{
    case Input = 'input';
    case Output = 'output';
    case Both = 'both';
}
