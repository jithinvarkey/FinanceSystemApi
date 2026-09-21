<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use Illuminate\Support\Facades\DB;

/**
 * P4.1–P4.5 — Customer master lifecycle.
 *
 * Create as draft -> submit (raises a maker-checker approval via the P0.4
 * engine, which flips the customer to active on approval) -> block/unblock,
 * and manage bank accounts (edits require re-verification, P4.4).
 */
final class CustomerService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): Customer
    {
        return DB::transaction(function () use ($data, $userId): Customer {
            $customer = Customer::query()->create([
                ...$data,
                'customer_code' => $this->numbers->next('customer'),
                'status' => CustomerStatus::Draft,
                'created_by' => $userId,
            ]);

            return $customer->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Customer $customer, array $data): Customer
    {
        if (! $customer->status->isEditable()) {
            throw FinanceRuleException::notEditable($customer->status->value);
        }

        // Never let the client move status/code/approval fields via update.
        unset($data['customer_code'], $data['status'], $data['created_by'], $data['approved_by']);

        $customer->update($data);

        return $customer->fresh();
    }

    /** Send the customer for maker-checker approval. */
    public function submit(Customer $customer, int $userId): Customer
    {
        if (! $customer->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($customer->status->value, CustomerStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($customer, $userId): Customer {
            $this->approvals->initiate($customer, 'customer', 0.0, $userId);

            $customer->update([
                'status' => CustomerStatus::PendingApproval,
                'rejection_reason' => null,
            ]);

            return $customer->fresh();
        });
    }

    public function deleteDraft(Customer $customer): void
    {
        if ($customer->status !== CustomerStatus::Draft) {
            throw FinanceRuleException::notEditable($customer->status->value);
        }

        $customer->delete();
    }

    /** P4.5 — block a customer from billing, with a mandatory reason. */
    public function block(Customer $customer, int $userId, string $reason): Customer
    {
        $customer->update([
            'is_blocked' => true,
            'block_reason' => $reason,
            'blocked_by' => $userId,
            'blocked_at' => now(),
        ]);

        return $customer->fresh();
    }

    public function unblock(Customer $customer): Customer
    {
        $customer->update([
            'is_blocked' => false,
            'block_reason' => null,
            'blocked_by' => null,
            'blocked_at' => null,
        ]);

        return $customer->fresh();
    }

    /** P4.4 — add a bank account (unverified until a checker confirms). */
    public function addBankAccount(Customer $customer, array $data): CustomerBankAccount
    {
        return DB::transaction(function () use ($customer, $data): CustomerBankAccount {
            if (! empty($data['is_primary'])) {
                $customer->bankAccounts()->update(['is_primary' => false]);
            }

            return $customer->bankAccounts()->create([
                ...$data,
                'is_verified' => false,
            ]);
        });
    }

    /** Editing bank details drops verification — it must be re-checked. */
    public function updateBankAccount(CustomerBankAccount $account, array $data): CustomerBankAccount
    {
        return DB::transaction(function () use ($account, $data): CustomerBankAccount {
            if (! empty($data['is_primary'])) {
                $account->customer->bankAccounts()->where('id', '!=', $account->id)->update(['is_primary' => false]);
            }

            $account->update([...$data, 'is_verified' => false]);

            return $account->fresh();
        });
    }

    /** A checker confirms the bank details are correct. */
    public function verifyBankAccount(CustomerBankAccount $account): CustomerBankAccount
    {
        $account->update(['is_verified' => true]);

        return $account->fresh();
    }
}
