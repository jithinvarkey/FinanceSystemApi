<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecurringJournalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\RecurringJournalResource;
use App\Models\RecurringJournal;
use App\Repositories\Contracts\RecurringJournalRepositoryInterface;
use App\Services\RecurringJournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * FIN-0039 — Recurring journal template endpoints.
 */
final class RecurringJournalController extends Controller
{
    public function __construct(
        private readonly RecurringJournalRepositoryInterface $templates,
        private readonly RecurringJournalService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return RecurringJournalResource::collection(
            $this->templates->paginate(
                filters: array_merge(
                    $request->only(['status', 'frequency']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    public function store(StoreRecurringJournalRequest $request): JsonResponse
    {
        $template = $this->service->create($request->validated(), (int) $request->user()->id);

        return (new RecurringJournalResource($template))->response()->setStatusCode(201);
    }

    public function show(RecurringJournal $recurring_journal): RecurringJournalResource
    {
        $this->authorize('general-ledger.view');

        return new RecurringJournalResource($recurring_journal->load('lines.account'));
    }

    public function pause(RecurringJournal $recurring_journal): RecurringJournalResource
    {
        $this->authorize('general-ledger.manage');

        return new RecurringJournalResource($this->service->pause($recurring_journal));
    }

    public function resume(RecurringJournal $recurring_journal): RecurringJournalResource
    {
        $this->authorize('general-ledger.manage');

        return new RecurringJournalResource($this->service->resume($recurring_journal));
    }

    /** Manual generation run (the scheduler calls the same service nightly). */
    public function generate(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.manage');

        $generated = $this->service->generateDue(Carbon::today(), (int) $request->user()->id);

        return JournalEntryResource::collection($generated);
    }
}
