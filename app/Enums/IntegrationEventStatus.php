<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Inbound integration (P-INT) — outcome of ingesting one event.
 * processed = a draft was created; duplicate = already ingested (no-op);
 * failed = validation or business-rule error.
 */
enum IntegrationEventStatus: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
