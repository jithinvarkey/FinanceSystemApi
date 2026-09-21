<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInsurerSettlementRequest;
use App\Http\Resources\InsurerSettlementResource;
use App\Http\Resources\PolicyResource;
use App\Models\InsurerSettlement;
use App\Models\Policy;
use App\Repositories\Contracts\InsurerSettlementRepositoryInterface;
use App\Services\InsurerSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 Slice B (§8.4) — Insurer settlement endpoints. Approval via the
 * approvals inbox; posting (Dr insurer payable → Cr bank) through
 * GlPostingService.
 */
final class InsurerSettlementController extends Controller
{
    public function __construct(
        private readonly InsurerSettlementRepositoryInterface $settlements,
        private readonly InsurerSettlementService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return InsurerSettlementResource::collection(
            $this->settlements->paginate(
                filters: array_merge(
                    $request->only(['status', 'insurer_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['insurer', 'bankAccount'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** Issued policies of an insurer with net premium still owed (settlement proposal). */
    public function settleablePolicies(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        $insurerId = (int) $request->integer('insurer_id');

        $policies = Policy::query()
            ->where('status', 'issued')
            ->when($insurerId > 0, fn ($q) => $q->where('insurer_id', $insurerId))
            ->whereRaw('(gross_premium - commission_amount - commission_tax_amount) > insurer_settled')
            ->with(['customer', 'insurer', 'product'])
            ->orderBy('policy_number')
            ->get();

        return PolicyResource::collection($policies);
    }

    public function store(StoreInsurerSettlementRequest $request): JsonResponse
    {
        $settlement = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new InsurerSettlementResource($settlement))->response()->setStatusCode(201);
    }

    public function show(InsurerSettlement $insurerSettlement): InsurerSettlementResource
    {
        $this->authorize('policies.view');

        return new InsurerSettlementResource(
            $insurerSettlement->load(['insurer', 'bankAccount', 'allocations.policy']),
        );
    }

    public function destroy(InsurerSettlement $insurerSettlement): JsonResponse
    {
        $this->authorize('policies.manage');
        $this->service->deleteDraft($insurerSettlement);

        return response()->json(null, 204);
    }

    public function update(StoreInsurerSettlementRequest $request, InsurerSettlement $insurerSettlement): InsurerSettlementResource
    {
        return new InsurerSettlementResource($this->service->updateDraft($insurerSettlement, $request->validated()));
    }

    public function submit(Request $request, InsurerSettlement $insurerSettlement): InsurerSettlementResource
    {
        $this->authorize('policies.manage');

        return new InsurerSettlementResource($this->service->submit($insurerSettlement, (int) $request->user()->id));
    }

    public function post(Request $request, InsurerSettlement $insurerSettlement): InsurerSettlementResource
    {
        $this->authorize('policies.post');

        return new InsurerSettlementResource($this->service->post($insurerSettlement, (int) $request->user()->id));
    }
}
