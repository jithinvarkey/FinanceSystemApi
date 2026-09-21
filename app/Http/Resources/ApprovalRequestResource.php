<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
final class ApprovalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'approvable_type' => class_basename($this->approvable_type),
            'approvable_id' => $this->approvable_id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'current_sequence' => $this->current_sequence,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->whenLoaded('requester', fn () => $this->requester->name),
            'workflow' => $this->whenLoaded('workflow', fn () => [
                'name' => $this->workflow->name,
                'steps' => $this->workflow->steps->map(fn ($s) => [
                    'sequence' => $s->sequence,
                    'name' => $s->name,
                    'required_permission' => $s->required_permission,
                    'min_amount' => $s->min_amount,
                    'is_current' => $s->sequence === $this->current_sequence,
                ])->values(),
            ]),
            'actions' => ApprovalActionResource::collection($this->whenLoaded('actions')),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
