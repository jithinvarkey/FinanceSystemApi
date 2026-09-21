<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalAction
 */
final class ApprovalActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'action' => $this->action->value,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor->name),
            'comment' => $this->comment,
            'acted_at' => $this->acted_at?->toIso8601String(),
        ];
    }
}
