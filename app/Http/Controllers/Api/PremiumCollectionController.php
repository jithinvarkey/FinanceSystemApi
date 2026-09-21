<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePremiumCollectionRequest;
use App\Http\Resources\PolicyResource;
use App\Http\Resources\PremiumCollectionResource;
use App\Models\Policy;
use App\Models\PremiumCollection;
use App\Repositories\Contracts\PremiumCollectionRepositoryInterface;
use App\Services\PremiumCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 Slice B (§8.3) — Premium collection endpoints. Approval via the
 * approvals inbox; posting (Dr bank → Cr customer receivable) through
 * GlPostingService.
 */
final class PremiumCollectionController extends Controller
{
    public function __construct(
        private readonly PremiumCollectionRepositoryInterface $collections,
        private readonly PremiumCollectionService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return PremiumCollectionResource::collection(
            $this->collections->paginate(
                filters: array_merge(
                    $request->only(['status', 'policy_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['policy.customer', 'bankAccount'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** Issued policies whose gross premium is not yet fully collected (collection proposal). */
    public function collectablePolicies(): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        $policies = Policy::query()
            ->where('status', 'issued')
            ->whereColumn('premium_collected', '<', 'gross_premium')
            ->with(['customer', 'insurer', 'product', 'installments'])
            ->orderBy('policy_number')
            ->get();

        return PolicyResource::collection($policies);
    }

    public function store(StorePremiumCollectionRequest $request): JsonResponse
    {
        $collection = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new PremiumCollectionResource($collection))->response()->setStatusCode(201);
    }

    public function show(PremiumCollection $premiumCollection): PremiumCollectionResource
    {
        $this->authorize('policies.view');

        return new PremiumCollectionResource(
            $premiumCollection->load(['policy.customer', 'bankAccount', 'allocations.installment']),
        );
    }

    public function destroy(PremiumCollection $premiumCollection): JsonResponse
    {
        $this->authorize('policies.manage');
        $this->service->deleteDraft($premiumCollection);

        return response()->json(null, 204);
    }

    public function update(StorePremiumCollectionRequest $request, PremiumCollection $premiumCollection): PremiumCollectionResource
    {
        return new PremiumCollectionResource($this->service->updateDraft($premiumCollection, $request->validated()));
    }

    public function submit(Request $request, PremiumCollection $premiumCollection): PremiumCollectionResource
    {
        $this->authorize('policies.manage');

        return new PremiumCollectionResource($this->service->submit($premiumCollection, (int) $request->user()->id));
    }

    public function post(Request $request, PremiumCollection $premiumCollection): PremiumCollectionResource
    {
        $this->authorize('policies.post');

        return new PremiumCollectionResource($this->service->post($premiumCollection, (int) $request->user()->id));
    }
}
