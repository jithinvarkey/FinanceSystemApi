<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationEventStatus;
use App\Enums\IntegrationEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Inbound integration (P-INT) — one ingested event (the sync log).
 */
final class IntegrationEvent extends Model
{
    protected $fillable = [
        'integration_client_id', 'source_system', 'event_type', 'external_id',
        'status', 'target_type', 'target_id', 'message', 'payload',
    ];

    protected $casts = [
        'event_type' => IntegrationEventType::class,
        'status' => IntegrationEventStatus::class,
        'payload' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'integration_client_id');
    }

    /** The resulting Policy / PolicyEndorsement, when processed. */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
