<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRenewalRequest;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use App\Repositories\Contracts\PolicyRepositoryInterface;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 — Policy endpoints. Approval runs through the generic approvals inbox;
 * issuance posts the fiduciary dual entry through GlPostingService.
 */
final class PolicyController extends Controller
{
    public function __construct(
        private readonly PolicyRepositoryInterface $policies,
        private readonly PolicyService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return PolicyResource::collection(
            $this->policies->paginate(
                filters: array_merge(
                    $request->only(['status', 'customer_id', 'insurer_id', 'product_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['customer', 'insurer', 'product'],
                perPage: min((int) $request->integer('per_page', 25), 100),
                withCount: ['endorsements'],
            ),
        );
    }

    public function store(StorePolicyRequest $request): JsonResponse
    {
        $policy = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new PolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(Policy $policy): PolicyResource
    {
        $this->authorize('policies.view');

        return new PolicyResource($policy->load(['customer', 'insurer', 'product', 'installments', 'endorsements', 'cancellations', 'renewedFrom', 'renewal']));
    }

    public function update(StorePolicyRequest $request, Policy $policy): PolicyResource
    {
        return new PolicyResource($this->service->updateDraft($policy, $request->validated()));
    }

    public function destroy(Policy $policy): JsonResponse
    {
        $this->authorize('policies.manage');
        $this->service->deleteDraft($policy);

        return response()->json(null, 204);
    }

    public function submit(Request $request, Policy $policy): PolicyResource
    {
        $this->authorize('policies.manage');

        return new PolicyResource($this->service->submit($policy, (int) $request->user()->id));
    }

    public function issue(Request $request, Policy $policy): PolicyResource
    {
        $this->authorize('policies.post');

        return new PolicyResource($this->service->issue($policy, (int) $request->user()->id));
    }

    /** Issued policies expiring within N days that have not yet been renewed. */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        $days = min(max((int) $request->integer('days', 60), 1), 365);

        $policies = Policy::query()
            ->where('status', 'issued')
            ->whereDate('end_date', '<=', now()->addDays($days)->toDateString())
            ->whereDoesntHave('renewal')
            ->with(['customer', 'insurer', 'product'])
            ->orderBy('end_date')
            ->get();

        return PolicyResource::collection($policies);
    }

    public function renew(StorePolicyRenewalRequest $request, Policy $policy): JsonResponse
    {
        $renewal = $this->service->renew($policy, $request->validated(), (int) $request->user()->id);

        return (new PolicyResource($renewal))->response()->setStatusCode(201);
    }
}
