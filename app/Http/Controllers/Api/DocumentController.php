<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * E12 — Document management with versioning.
 */
final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $request->validate([
            'owner_type' => ['required', 'string'],
            'owner_id' => ['required', 'integer'],
        ]);

        $owner = $this->service->resolveOwner($data['owner_type'], (int) $data['owner_id']);

        return response()->json(['data' => $this->service->listFor($owner)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $request->validate([
            'owner_type' => ['required', 'string'],
            'owner_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:250'],
            'file' => ['required', 'file', 'max:10240'],   // 10 MB
        ]);

        $owner = $this->service->resolveOwner($data['owner_type'], (int) $data['owner_id']);
        $document = $this->service->upload($owner, $data['title'], $data['category'] ?? null, $request->file('file'), $data['notes'] ?? null, (int) $request->user()->id);

        return response()->json(['data' => $document->load('latestVersion')], 201);
    }

    public function versions(Document $document): JsonResponse
    {
        $this->authorize('finance-config.manage');

        return response()->json(['data' => $document->versions()->with('document:id,title')->get()]);
    }

    public function archive(Document $document): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $this->service->archive($document);

        return response()->json(['data' => ['archived' => true]]);
    }

    public function download(DocumentVersion $documentVersion): BinaryFileResponse
    {
        $this->authorize('finance-config.manage');
        $f = $this->service->download($documentVersion);

        return response()->download($f['path'], $f['name'], ['Content-Type' => $f['mime']]);
    }
}
