<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Notifications\ApprovalDecidedNotification;
use App\Notifications\ApprovalRequestedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * F19 — fans approval-lifecycle notifications out to the right people. In-app
 * (database) always; e-mail too when a mailer is configured. Sending is
 * best-effort: a delivery failure is logged, never bubbled into the approval
 * transaction.
 */
final class ApprovalNotifier
{
    /** document_type → the permission that lets a user approve it. */
    private const APPROVE_PERMISSION = [
        'vendor' => 'accounts-payable.approve',
        'vendor_invoice' => 'accounts-payable.approve',
        'vendor_payment' => 'accounts-payable.approve',
        'expense_claim' => 'accounts-payable.approve',
        'customer' => 'accounts-receivable.approve',
        'customer_invoice' => 'accounts-receivable.approve',
        'receipt' => 'accounts-receivable.approve',
        'customer_advance' => 'accounts-receivable.approve',
        'journal' => 'general-ledger.approve',
        'policy' => 'policies.approve',
        'endorsement' => 'policies.approve',
        'policy_cancellation' => 'policies.approve',
    ];

    /** Notify eligible approvers that a document is waiting on them. */
    public function requested(ApprovalRequest $request): void
    {
        $permission = self::APPROVE_PERMISSION[$request->document_type] ?? 'general-ledger.approve';

        $approvers = User::query()
            ->where('is_active', true)
            ->whereKeyNot($request->requested_by) // raiser cannot approve their own
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', $permission))
            ->get();

        if ($approvers->isEmpty()) {
            return;
        }

        $requesterName = User::query()->whereKey($request->requested_by)->value('name') ?? 'A colleague';

        $this->safely(fn () => Notification::send($approvers, new ApprovalRequestedNotification($request, (string) $requesterName)));
    }

    /** Notify the raiser of the outcome. */
    public function decided(ApprovalRequest $request, bool $approved, ?string $reason = null): void
    {
        $requester = User::query()->find($request->requested_by);
        if ($requester === null) {
            return;
        }

        $this->safely(fn () => $requester->notify(new ApprovalDecidedNotification($request, $approved, $reason)));
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('Approval notification failed: '.$e->getMessage());
        }
    }
}
