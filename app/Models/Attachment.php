<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P0.6 — One stored file attached to any document.
 *
 * @property string $path
 * @property string $disk
 * @property string $original_name
 */
final class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attachable_type', 'attachable_id', 'disk', 'path', 'original_name',
        'mime_type', 'size', 'category', 'retention_until', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'retention_until' => 'date',
    ];

    /** @return MorphTo<Model, Attachment> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, Attachment> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Is the file still inside its mandated retention window? */
    public function isWithinRetention(): bool
    {
        return $this->retention_until !== null && $this->retention_until->isFuture();
    }
}
