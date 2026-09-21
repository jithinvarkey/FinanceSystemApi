<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LineOfBusiness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LineOfBusiness
 */
final class LineOfBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'is_life' => $this->is_life,
            'status' => $this->status,
        ];
    }
}
