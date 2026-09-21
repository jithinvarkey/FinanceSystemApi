<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * P0.4 — Generic maker-checker approval engine.
 *
 * Documents route through here by document type. The engine resolves the
 * applicable steps for the amount (threshold routing), advances one step per
 * approval, and enforces segregation of duties: the raiser may not approve,
 * and no single approver may clear more than one step of the same request.
 */
final class ApprovalService
{
    public function __construct(private readonly ApprovalNotifier $notifier)
    {
    }

    /**
     * Start an approval run for a document. If no step applies (amount below
     * every threshold), the request is auto-approved.
     *
     * @throws FinanceRuleException When no active workflow exists for the type.
     */
    public function initiate(Model $document, string $documentType, float $amount, int $requestedBy): ApprovalRequest
    {
        $workflow = ApprovalWorkflow::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->first()
            ?? throw FinanceRuleException::noWorkflow($documentType);

        $first = $this->applicableSteps($workflow, $amount)->first();

        $request = ApprovalRequest::query()->create([
            'approvable_type' => $document::class,
            'approvable_id' => $document->getKey(),
            'approval_workflow_id' => $workflow->id,
            'document_type' => $documentType,
            'amount' => round($amount, 2),
            'status' => $first === null ? ApprovalStatus::Approved : ApprovalStatus::Pending,
            'current_sequence' => $first?->sequence,
            'requested_by' => $requestedBy,
            'decided_at' => $first === null ? now() : null,
        ]);

        // F19 — alert eligible approvers when the document actually needs a decision.
        if ($request->status === ApprovalStatus::Pending) {
            $this->notifier->requested($request);
        }

        return $request;
    }

    /**
     * Record an approval for the current step and advance, or finalise.
     *
     * @throws FinanceRuleException
     */
    public function approve(ApprovalRequest $request, User $actor, ?string $comment = null): ApprovalRequest
    {
        $step = $this->guardAction($request, $actor);

        $result = DB::transaction(function () use ($request, $step, $actor, $comment): ApprovalRequest {
            $this->record($request, $step, $actor, ApprovalActionType::Approved, $comment);

            $next = $this->nextApplicableStep($request, $step->sequence);

            if ($next === null) {
                $request->update([
                    'status' => ApprovalStatus::Approved,
                    'current_sequence' => null,
                    'decided_at' => now(),
                ]);
                $this->notifyDocument($request, approved: true);
            } else {
                $request->update(['current_sequence' => $next->sequence]);
            }

            return $request->refresh();
        });

        // F19 — tell the raiser, once fully approved (after the txn commits).
        if ($result->status === ApprovalStatus::Approved) {
            $this->notifier->decided($result, approved: true);
        }

        return $result;
    }

    /**
     * Reject at the current step — terminal for the request.
     *
     * @throws FinanceRuleException
     */
    public function reject(ApprovalRequest $request, User $actor, string $reason): ApprovalRequest
    {
        $step = $this->guardAction($request, $actor);

        $result = DB::transaction(function () use ($request, $step, $actor, $reason): ApprovalRequest {
            $this->record($request, $step, $actor, ApprovalActionType::Rejected, $reason);

            $request->update([
                'status' => ApprovalStatus::Rejected,
                'current_sequence' => null,
                'decided_at' => now(),
            ]);
            $this->notifyDocument($request, approved: false);

            return $request->refresh();
        });

        // F19 — tell the raiser it was rejected (after the txn commits).
        $this->notifier->decided($result, approved: false, reason: $reason);

        return $result;
    }

    /**
     * Let the underlying document react to a terminal approval outcome.
     */
    private function notifyDocument(ApprovalRequest $request, bool $approved): void
    {
        $document = $request->approvable;

        if ($document instanceof Approvable) {
            $approved
                ? $document->onApprovalApproved($request)
                : $document->onApprovalRejected($request);
        }
    }

    /** The raiser may withdraw their own still-pending request. */
    public function cancel(ApprovalRequest $request, int $byUserId): ApprovalRequest
    {
        if (! $request->status->isOpen()) {
            throw FinanceRuleException::approvalNotPending();
        }

        $request->update([
            'status' => ApprovalStatus::Cancelled,
            'current_sequence' => null,
            'decided_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Requests awaiting THIS user's action: pending, not raised by them, not
     * already acted on by them, and whose current step demands a permission
     * they hold.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function pendingFor(User $user): Collection
    {
        $permissions = $user->permissionNames();

        return ApprovalRequest::query()
            ->with(['workflow.steps', 'requester', 'approvable'])
            ->where('status', ApprovalStatus::Pending)
            ->where('requested_by', '!=', $user->id)
            ->whereDoesntHave('actions', fn ($q) => $q->where('actor_id', $user->id))
            ->get()
            ->filter(function (ApprovalRequest $request) use ($permissions): bool {
                $step = $request->workflow->steps->firstWhere('sequence', $request->current_sequence);

                return $step !== null && in_array($step->required_permission, $permissions, true);
            })
            ->values();
    }

    // ----- internals -----

    /**
     * Validate the actor may clear the current step; return that step.
     *
     * @throws FinanceRuleException
     */
    private function guardAction(ApprovalRequest $request, User $actor): ApprovalStep
    {
        if (! $request->status->isOpen()) {
            throw FinanceRuleException::approvalNotPending();
        }

        if ((int) $request->requested_by === $actor->id) {
            throw FinanceRuleException::approvalSelf();
        }

        if ($request->actions()->where('actor_id', $actor->id)->exists()) {
            throw FinanceRuleException::approvalAlreadyActed();
        }

        $step = ApprovalStep::query()
            ->where('approval_workflow_id', $request->approval_workflow_id)
            ->where('sequence', $request->current_sequence)
            ->firstOrFail();

        if (! $actor->hasPermission($step->required_permission)) {
            throw FinanceRuleException::approvalPermission($step->required_permission);
        }

        return $step;
    }

    private function record(ApprovalRequest $request, ApprovalStep $step, User $actor, ApprovalActionType $action, ?string $comment): void
    {
        ApprovalAction::query()->create([
            'approval_request_id' => $request->id,
            'approval_step_id' => $step->id,
            'sequence' => $step->sequence,
            'action' => $action,
            'actor_id' => $actor->id,
            'comment' => $comment,
            'acted_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, ApprovalStep>
     */
    private function applicableSteps(ApprovalWorkflow $workflow, float $amount): Collection
    {
        return $workflow->steps()
            ->where('is_active', true)
            ->where('min_amount', '<=', round($amount, 2))
            ->orderBy('sequence')
            ->get();
    }

    private function nextApplicableStep(ApprovalRequest $request, int $afterSequence): ?ApprovalStep
    {
        return ApprovalStep::query()
            ->where('approval_workflow_id', $request->approval_workflow_id)
            ->where('is_active', true)
            ->where('min_amount', '<=', $request->amount)
            ->where('sequence', '>', $afterSequence)
            ->orderBy('sequence')
            ->first();
    }
}
