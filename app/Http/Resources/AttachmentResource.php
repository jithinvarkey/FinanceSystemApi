<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
final class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attachable_type' => class_basename($this->attachable_type),
            'attachable_id' => $this->attachable_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'category' => $this->category,
            'retention_until' => $this->retention_until?->toDateString(),
            'within_retention' => $this->isWithinRetention(),
            'uploaded_by' => $this->uploaded_by,
            'uploaded_by_name' => $this->whenLoaded('uploader', fn () => $this->uploader->name),
            'download_path' => "/api/v1/attachments/{$this->id}/download",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
