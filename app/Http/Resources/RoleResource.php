<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
            'user_count' => $this->whenCounted('users'),
        ];
    }
}
