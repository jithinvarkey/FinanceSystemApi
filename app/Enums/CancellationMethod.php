<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.17 Slice C — How the earned/unearned premium split is computed on cancellation.
 */
enum CancellationMethod: string
{
    case ProRata = 'pro_rata';
    case ShortRate = 'short_rate';
}
