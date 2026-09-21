<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectApprovalRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Repositories\Contracts\ApprovalRequestRepositoryInterface;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P0.4 — Approval inbox & actions. Authorization for acting on a step is
 * enforced inside ApprovalService (the step's permission + segregation of
 * duties); these endpoints only require the holder to be authenticated and,
 * for read views, to hold 'approvals.view'.
 */
final class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestRepositoryInterface $requests,
        private readonly ApprovalService $service,
    ) {
    }

    /** Requests awaiting the current user's decision. */
    public function pending(Request $request): AnonymousResourceCollection
    {
        return ApprovalRequestResource::collection(
            $this->service->pendingFor($request->user()),
        );
    }

    /** Full register of approval requests, filterable by status/document type. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('approvals.view');

        return ApprovalRequestResource::collection(
            $this->requests->paginate(
                filters: array_merge(
                    $request->only(['status', 'document_type']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['requester', 'workflow.steps'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    public function show(ApprovalRequest $approval): ApprovalRequestResource
    {
        $this->authorize('approvals.view');

        return new ApprovalRequestResource(
            $approval->load(['requester', 'workflow.steps', 'actions.actor', 'approvable']),
        );
    }

    public function approve(Request $request, ApprovalRequest $approval): ApprovalRequestResource
    {
        $comment = $request->string('comment')->toString() ?: null;

        $updated = $this->service->approve($approval, $request->user(), $comment);

        return new ApprovalRequestResource($updated->load(['workflow.steps', 'actions.actor']));
    }

    public function reject(RejectApprovalRequest $request, ApprovalRequest $approval): ApprovalRequestResource
    {
        $updated = $this->service->reject($approval, $request->user(), $request->string('reason')->toString());

        return new ApprovalRequestResource($updated->load(['workflow.steps', 'actions.actor']));
    }

    public function cancel(Request $request, ApprovalRequest $approval): ApprovalRequestResource|JsonResponse
    {
        // Only the raiser may withdraw their own request.
        if ((int) $approval->requested_by !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the raiser can cancel this request.'], 403);
        }

        return new ApprovalRequestResource($this->service->cancel($approval, (int) $request->user()->id));
    }
}
