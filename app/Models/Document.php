<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * E12 — a versioned document attached to any entity.
 */
final class Document extends Model
{
    protected $fillable = ['documentable_type', 'documentable_id', 'title', 'category', 'current_version', 'status', 'created_by'];

    /** @return MorphTo<Model, Document> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<DocumentVersion> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    /** @return HasOne<DocumentVersion> The highest-numbered (latest) version. JSON key `latest_version` (avoids colliding with the `current_version` int column). */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->latestOfMany('version_number');
    }
}
