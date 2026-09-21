<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyCancellationRequest;
use App\Http\Resources\PolicyCancellationResource;
use App\Models\Policy;
use App\Models\PolicyCancellation;
use App\Services\PolicyCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 Slice C (§10) — Policy cancellation endpoints. Approval via the
 * approvals inbox; posting refunds the unearned premium and claws back the
 * matching commission through GlPostingService.
 */
final class PolicyCancellationController extends Controller
{
    public function __construct(private readonly PolicyCancellationService $service)
    {
    }

    public function index(Policy $policy): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return PolicyCancellationResource::collection($policy->cancellations()->get());
    }

    public function store(StorePolicyCancellationRequest $request, Policy $policy): JsonResponse
    {
        $cancellation = $this->service->createDraft($policy, $request->validated(), (int) $request->user()->id);

        return (new PolicyCancellationResource($cancellation))->response()->setStatusCode(201);
    }

    public function show(PolicyCancellation $cancellation): PolicyCancellationResource
    {
        $this->authorize('policies.view');

        return new PolicyCancellationResource($cancellation);
    }

    public function update(StorePolicyCancellationRequest $request, PolicyCancellation $cancellation): PolicyCancellationResource
    {
        return new PolicyCancellationResource($this->service->updateDraft($cancellation, $request->validated()));
    }

    public function destroy(PolicyCancellation $cancellation): JsonResponse
    {
        $this->authorize('policies.manage');
        $this->service->deleteDraft($cancellation);

        return response()->json(null, 204);
    }

    public function submit(Request $request, PolicyCancellation $cancellation): PolicyCancellationResource
    {
        $this->authorize('policies.manage');

        return new PolicyCancellationResource($this->service->submit($cancellation, (int) $request->user()->id));
    }

    public function post(Request $request, PolicyCancellation $cancellation): PolicyCancellationResource
    {
        $this->authorize('policies.post');

        return new PolicyCancellationResource($this->service->post($cancellation, (int) $request->user()->id));
    }
}
