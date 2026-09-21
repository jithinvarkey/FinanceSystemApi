<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Inbound integration (P-INT) — the kind of upstream event ingested.
 */
enum IntegrationEventType: string
{
    case Policy = 'policy';
    case Endorsement = 'endorsement';
    case Renewal = 'renewal';
}
