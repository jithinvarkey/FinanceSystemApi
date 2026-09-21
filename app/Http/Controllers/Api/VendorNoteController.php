<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorNoteRequest;
use App\Http\Resources\VendorNoteResource;
use App\Models\VendorNote;
use App\Services\VendorNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AP credit & debit notes. Reuses the AP permission set.
 */
final class VendorNoteController extends Controller
{
    public function __construct(private readonly VendorNoteService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        $notes = VendorNote::query()->with('vendor')
            ->when($request->filled('type'), fn ($q) => $q->where('note_type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')->limit(200)->get();

        return VendorNoteResource::collection($notes);
    }

    public function store(StoreVendorNoteRequest $request): JsonResponse
    {
        $note = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new VendorNoteResource($note->load('vendor')))->response()->setStatusCode(201);
    }

    public function show(VendorNote $vendorNote): VendorNoteResource
    {
        $this->authorize('accounts-payable.view');

        return new VendorNoteResource($vendorNote->load(['lines.account', 'vendor']));
    }

    public function update(StoreVendorNoteRequest $request, VendorNote $vendorNote): VendorNoteResource
    {
        return new VendorNoteResource($this->service->updateDraft($vendorNote, $request->validated())->load('vendor'));
    }

    public function destroy(Request $request, VendorNote $vendorNote): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($vendorNote);

        return response()->json(status: 204);
    }

    public function submit(Request $request, VendorNote $vendorNote): VendorNoteResource
    {
        $this->authorize('accounts-payable.manage');

        return new VendorNoteResource($this->service->submit($vendorNote, (int) $request->user()->id)->load('vendor'));
    }

    public function post(Request $request, VendorNote $vendorNote): VendorNoteResource
    {
        $this->authorize('accounts-payable.post');

        return new VendorNoteResource($this->service->post($vendorNote, (int) $request->user()->id)->load('vendor'));
    }
}
