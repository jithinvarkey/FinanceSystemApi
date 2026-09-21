<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P7 — Fixed asset lifecycle.
 */
enum AssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
}
