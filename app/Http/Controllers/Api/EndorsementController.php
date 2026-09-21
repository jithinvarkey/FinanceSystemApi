<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEndorsementRequest;
use App\Http\Resources\PolicyEndorsementResource;
use App\Models\Policy;
use App\Models\PolicyEndorsement;
use App\Services\EndorsementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 — Policy endorsement endpoints. Approval via the approvals inbox;
 * posting writes the delta fiduciary entry through GlPostingService.
 */
final class EndorsementController extends Controller
{
    public function __construct(private readonly EndorsementService $service)
    {
    }

    public function index(Policy $policy): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return PolicyEndorsementResource::collection($policy->endorsements()->get());
    }

    public function store(StoreEndorsementRequest $request, Policy $policy): JsonResponse
    {
        $endorsement = $this->service->createDraft($policy, $request->validated(), (int) $request->user()->id);

        return (new PolicyEndorsementResource($endorsement))->response()->setStatusCode(201);
    }

    public function show(PolicyEndorsement $endorsement): PolicyEndorsementResource
    {
        $this->authorize('policies.view');

        return new PolicyEndorsementResource($endorsement);
    }

    public function update(StoreEndorsementRequest $request, PolicyEndorsement $endorsement): PolicyEndorsementResource
    {
        return new PolicyEndorsementResource($this->service->updateDraft($endorsement, $request->validated()));
    }

    public function destroy(PolicyEndorsement $endorsement): JsonResponse
    {
        $this->authorize('policies.manage');
        $this->service->deleteDraft($endorsement);

        return response()->json(null, 204);
    }

    public function submit(Request $request, PolicyEndorsement $endorsement): PolicyEndorsementResource
    {
        $this->authorize('policies.manage');

        return new PolicyEndorsementResource($this->service->submit($endorsement, (int) $request->user()->id));
    }

    public function post(Request $request, PolicyEndorsement $endorsement): PolicyEndorsementResource
    {
        $this->authorize('policies.post');

        return new PolicyEndorsementResource($this->service->post($endorsement, (int) $request->user()->id));
    }
}
