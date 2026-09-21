<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * P0.6 — Stores and removes document attachments.
 *
 * Files are written to a private disk under a per-document-type folder; the
 * row records provenance and a retention date. A virus-scan hook is provided
 * for a real scanner to be wired in later.
 */
final class AttachmentService
{
    private const DISK = 'local';
    private const DEFAULT_RETENTION_YEARS = 6;

    /**
     * Persist an uploaded file against a document and index it.
     *
     * @param array{attachable_type: string, attachable_id: int, category?: ?string, retention_years?: ?int} $meta
     */
    public function store(UploadedFile $file, array $meta, int $uploadedBy): Attachment
    {
        $this->scan($file);

        $folder = 'attachments/'.str_replace('\\', '_', $meta['attachable_type']);
        $path = $file->store($folder, self::DISK);

        $years = $meta['retention_years'] ?? self::DEFAULT_RETENTION_YEARS;

        return Attachment::query()->create([
            'attachable_type' => $meta['attachable_type'],
            'attachable_id' => $meta['attachable_id'],
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'category' => $meta['category'] ?? null,
            'retention_until' => now()->addYears($years)->toDateString(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Permanently remove the file and its row.
     */
    public function purge(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->forceDelete();
    }

    /**
     * Hook for malware scanning before a file is accepted.
     * Intentionally a no-op until a scanner (e.g. ClamAV) is integrated.
     */
    private function scan(UploadedFile $file): void
    {
        // no-op placeholder — see P12 (security hardening)
    }
}
