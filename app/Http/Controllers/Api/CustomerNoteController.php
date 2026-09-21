<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerNoteRequest;
use App\Http\Resources\CustomerNoteResource;
use App\Models\CustomerNote;
use App\Services\CustomerNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * AR credit & debit notes. Reuses the AR permission set.
 */
final class CustomerNoteController extends Controller
{
    public function __construct(private readonly CustomerNoteService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        $notes = CustomerNote::query()->with('customer')
            ->when($request->filled('type'), fn ($q) => $q->where('note_type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')->limit(200)->get();

        return CustomerNoteResource::collection($notes);
    }

    public function store(StoreCustomerNoteRequest $request): JsonResponse
    {
        $note = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new CustomerNoteResource($note->load('customer')))->response()->setStatusCode(201);
    }

    public function show(CustomerNote $customerNote): CustomerNoteResource
    {
        $this->authorize('accounts-receivable.view');

        return new CustomerNoteResource($customerNote->load(['lines.account', 'customer']));
    }

    public function update(StoreCustomerNoteRequest $request, CustomerNote $customerNote): CustomerNoteResource
    {
        return new CustomerNoteResource($this->service->updateDraft($customerNote, $request->validated())->load('customer'));
    }

    public function destroy(Request $request, CustomerNote $customerNote): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $this->service->deleteDraft($customerNote);

        return response()->json(status: 204);
    }

    public function submit(Request $request, CustomerNote $customerNote): CustomerNoteResource
    {
        $this->authorize('accounts-receivable.manage');

        return new CustomerNoteResource($this->service->submit($customerNote, (int) $request->user()->id)->load('customer'));
    }

    public function post(Request $request, CustomerNote $customerNote): CustomerNoteResource
    {
        $this->authorize('accounts-receivable.post');

        return new CustomerNoteResource($this->service->post($customerNote, (int) $request->user()->id)->load('customer'));
    }
}
