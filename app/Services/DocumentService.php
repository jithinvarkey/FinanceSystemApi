<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\FixedAsset;
use App\Models\Policy;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * E12 — Document management with versioning. Files are stored on the local disk
 * under documents/{document_id}/; re-uploading under an existing title adds a
 * new version and bumps current_version while keeping every prior revision.
 */
final class DocumentService
{
    /** Whitelisted owner aliases → model classes (prevents arbitrary morph targets). */
    private const OWNERS = [
        'vendor' => Vendor::class,
        'customer' => Customer::class,
        'fixed_asset' => FixedAsset::class,
        'policy' => Policy::class,
        'vendor_invoice' => VendorInvoice::class,
    ];

    public function resolveOwner(string $alias, int $id): Model
    {
        $class = self::OWNERS[$alias] ?? null;
        if ($class === null) {
            throw new FinanceRuleException("Unknown document owner '{$alias}'.");
        }

        return $class::query()->findOrFail($id);
    }

    /**
     * Upload a file. If $title already exists for this owner, a new version is
     * added; otherwise a new document is created at version 1.
     */
    public function upload(Model $owner, string $title, ?string $category, UploadedFile $file, ?string $notes, int $userId): Document
    {
        return DB::transaction(function () use ($owner, $title, $category, $file, $notes, $userId): Document {
            $document = Document::query()->firstOrCreate(
                ['documentable_type' => $owner::class, 'documentable_id' => $owner->getKey(), 'title' => $title],
                ['category' => $category, 'current_version' => 0, 'status' => 'active', 'created_by' => $userId],
            );

            $next = (int) $document->current_version + 1;
            $path = $file->store("documents/{$document->id}");
            if ($path === false) {
                throw new FinanceRuleException('Failed to store the uploaded file.');
            }

            DocumentVersion::query()->create([
                'document_id' => $document->id, 'version_number' => $next,
                'file_name' => $file->getClientOriginalName(), 'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(), 'storage_path' => $path, 'notes' => $notes, 'uploaded_by' => $userId,
            ]);

            $document->update(['current_version' => $next, 'category' => $category ?? $document->category, 'status' => 'active']);

            return $document->fresh(['latestVersion']);
        });
    }

    /** @return Collection<int, Document> */
    public function listFor(Model $owner): Collection
    {
        return Document::query()
            ->where('documentable_type', $owner::class)->where('documentable_id', $owner->getKey())
            ->with('latestVersion')->withCount('versions')
            ->latest('id')->get();
    }

    public function archive(Document $document): void
    {
        $document->update(['status' => 'archived']);
    }

    /** Resolve a stored version for download (full filesystem path + original name). */
    public function download(DocumentVersion $version): array
    {
        if (! Storage::exists($version->storage_path)) {
            throw new FinanceRuleException('The stored file is missing.');
        }

        return ['path' => Storage::path($version->storage_path), 'name' => $version->file_name, 'mime' => $version->mime_type ?? 'application/octet-stream'];
    }
}
