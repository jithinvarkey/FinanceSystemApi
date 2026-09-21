<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E12 — one stored revision of a document.
 */
final class DocumentVersion extends Model
{
    protected $fillable = ['document_id', 'version_number', 'file_name', 'mime_type', 'size_bytes', 'storage_path', 'notes', 'uploaded_by'];

    protected $casts = ['size_bytes' => 'integer', 'version_number' => 'integer'];

    /** @return BelongsTo<Document, DocumentVersion> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
