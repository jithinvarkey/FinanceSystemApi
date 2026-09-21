<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

/**
 * Record-level authorization for journal entries.
 *
 * Each ability still requires the coarse permission the route always
 * demanded, but routing it through a policy gives a single place to add
 * record scoping (e.g. company / branch / cost-centre ownership) so a user
 * cannot act on a journal outside their remit by guessing its id (IDOR).
 *
 * Segregation-of-duties (creator may not approve/post) remains enforced in
 * JournalService, which owns the document workflow.
 */
final class JournalEntryPolicy
{
    public function view(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.view', $journal);
    }

    public function update(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.manage', $journal);
    }

    public function delete(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.manage', $journal);
    }

    public function submit(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.manage', $journal);
    }

    public function approve(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.approve', $journal);
    }

    public function reject(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.approve', $journal);
    }

    public function post(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.post', $journal);
    }

    public function reverse(User $user, JournalEntry $journal): bool
    {
        return $this->permits($user, 'general-ledger.post', $journal);
    }

    /**
     * Coarse permission check plus the record-scoping hook.
     *
     * Extend $withinScope once journals carry an owning entity so that
     * holding the ability is necessary but not sufficient — the journal
     * must also fall within the user's authorized scope.
     */
    private function permits(User $user, string $ability, JournalEntry $journal): bool
    {
        return $user->can($ability) && $this->withinScope($user, $journal);
    }

    private function withinScope(User $user, JournalEntry $journal): bool
    {
        // No tenant/branch boundary exists on the schema yet; every authorized
        // user shares one ledger. Tighten here when multi-entity lands.
        return true;
    }
}
