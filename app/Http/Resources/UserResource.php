<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->label,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
