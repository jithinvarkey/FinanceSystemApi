<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Policy;
use App\Models\PolicyEndorsement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inbound integration (P-INT) — the ingest result returned to the caller.
 *
 * @mixin \App\Models\IntegrationEvent
 */
final class IntegrationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'message' => $this->message,
            'source_system' => $this->source_system,
            'client' => $this->whenLoaded('client', fn () => $this->client?->name),
            'target_type' => $this->target_type ? class_basename($this->target_type) : null,
            'target_id' => $this->target_id,
            'document_number' => $this->documentNumber(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function documentNumber(): ?string
    {
        $target = $this->target;

        return match (true) {
            $target instanceof Policy => $target->policy_number,
            $target instanceof PolicyEndorsement => $target->endorsement_number,
            default => null,
        };
    }
}
