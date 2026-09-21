<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P0.6 — Upload, list, download and remove document attachments.
 */
final class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $service)
    {
    }

    /** Attachments for one document (?attachable_type=&attachable_id=). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'attachable_type' => ['required', 'string', 'max:100'],
            'attachable_id' => ['required', 'integer', 'min:1'],
        ]);

        $attachments = Attachment::query()
            ->where('attachable_type', $validated['attachable_type'])
            ->where('attachable_id', $validated['attachable_id'])
            ->with('uploader')
            ->latest('id')
            ->get();

        return AttachmentResource::collection($attachments);
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->service->store(
            $request->file('file'),
            $request->safe()->only(['attachable_type', 'attachable_id', 'category', 'retention_years']),
            (int) $request->user()->id,
        );

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(
            Storage::disk($attachment->disk)->exists($attachment->path),
            404,
            'The stored file is missing.',
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        // Access control: only the uploader or a finance administrator may remove.
        $user = $request->user();
        if ((int) $attachment->uploaded_by !== (int) $user->id && ! $user->can('finance-config.manage')) {
            return response()->json(['message' => 'You may not delete this attachment.'], 403);
        }

        $this->service->purge($attachment);

        return response()->json(null, 204);
    }
}
