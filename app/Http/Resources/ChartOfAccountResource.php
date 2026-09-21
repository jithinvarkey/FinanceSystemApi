<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ChartOfAccount
 */
final class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type->value,
            'normal_balance' => $this->normal_balance->value,
            'parent_id' => $this->parent_id,
            'level' => $this->level,
            'is_postable' => $this->is_postable,
            'is_bank_account' => $this->is_bank_account,
            'is_control_account' => $this->is_control_account,
            'status' => $this->status->value,
            'description' => $this->description,
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
