<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectJournalRequest;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * FIN-0036..0038 — Journal entry endpoints.
 * Thin controller: validation in FormRequests, rules in JournalService.
 */
final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $journals,
        private readonly JournalService $service,
    ) {
    }

    /** Paginated journal register with status/date filters and search. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return JournalEntryResource::collection(
            $this->journals->paginate(
                filters: array_merge(
                    $request->only(['status', 'fiscal_period_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['lines.account'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $journal = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new JournalEntryResource($journal))->response()->setStatusCode(201);
    }

    public function show(JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('view', $journal);

        return new JournalEntryResource($journal->load('lines.account'));
    }

    public function update(StoreJournalEntryRequest $request, JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('update', $journal);

        return new JournalEntryResource(
            $this->service->updateDraft($journal, $request->validated(), (int) $request->user()->id),
        );
    }

    public function destroy(JournalEntry $journal): JsonResponse
    {
        $this->authorize('delete', $journal);
        $this->service->deleteDraft($journal);

        return response()->json(null, 204);
    }

    public function submit(Request $request, JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('submit', $journal);

        return new JournalEntryResource($this->service->submit($journal, (int) $request->user()->id));
    }

    public function approve(Request $request, JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('approve', $journal);

        return new JournalEntryResource($this->service->approve($journal, (int) $request->user()->id));
    }

    public function reject(RejectJournalRequest $request, JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('reject', $journal);

        return new JournalEntryResource(
            $this->service->reject($journal, (int) $request->user()->id, $request->string('reason')->toString()),
        );
    }

    public function post(Request $request, JournalEntry $journal): JournalEntryResource
    {
        $this->authorize('post', $journal);

        return new JournalEntryResource($this->service->post($journal, (int) $request->user()->id));
    }

    public function reverse(Request $request, JournalEntry $journal): JsonResponse
    {
        $this->authorize('reverse', $journal);

        $request->validate(['reversal_date' => ['required', 'date']]);

        $reversal = $this->service->reverse(
            $journal,
            (int) $request->user()->id,
            Carbon::parse($request->string('reversal_date')->toString()),
        );

        return (new JournalEntryResource($reversal))->response()->setStatusCode(201);
    }
}
