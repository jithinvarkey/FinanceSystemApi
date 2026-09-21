<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VendorStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Vendor;
use App\Models\VendorBankAccount;
use Illuminate\Support\Facades\DB;

/**
 * P3.1–P3.5 — Vendor master lifecycle.
 *
 * Create as draft -> submit (raises a maker-checker approval via the P0.4
 * engine, which flips the vendor to active on approval) -> block/unblock,
 * and manage beneficiary bank accounts (edits require re-verification, P3.4).
 */
final class VendorService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): Vendor
    {
        return DB::transaction(function () use ($data, $userId): Vendor {
            $vendor = Vendor::query()->create([
                ...$data,
                'vendor_code' => $this->numbers->next('vendor'),
                'status' => VendorStatus::Draft,
                'created_by' => $userId,
            ]);

            return $vendor->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Vendor $vendor, array $data): Vendor
    {
        if (! $vendor->status->isEditable()) {
            throw FinanceRuleException::notEditable($vendor->status->value);
        }

        // Never let the client move status/code/approval fields via update.
        unset($data['vendor_code'], $data['status'], $data['created_by'], $data['approved_by']);

        $vendor->update($data);

        return $vendor->fresh();
    }

    /** Send the vendor for maker-checker approval. */
    public function submit(Vendor $vendor, int $userId): Vendor
    {
        if (! $vendor->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($vendor->status->value, VendorStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($vendor, $userId): Vendor {
            $this->approvals->initiate($vendor, 'vendor', 0.0, $userId);

            $vendor->update([
                'status' => VendorStatus::PendingApproval,
                'rejection_reason' => null,
            ]);

            return $vendor->fresh();
        });
    }

    public function deleteDraft(Vendor $vendor): void
    {
        if ($vendor->status !== VendorStatus::Draft) {
            throw FinanceRuleException::notEditable($vendor->status->value);
        }

        $vendor->delete();
    }

    /** P3.5 — block a vendor from invoicing/payment, with a mandatory reason. */
    public function block(Vendor $vendor, int $userId, string $reason): Vendor
    {
        $vendor->update([
            'is_blocked' => true,
            'block_reason' => $reason,
            'blocked_by' => $userId,
            'blocked_at' => now(),
        ]);

        return $vendor->fresh();
    }

    public function unblock(Vendor $vendor): Vendor
    {
        $vendor->update([
            'is_blocked' => false,
            'block_reason' => null,
            'blocked_by' => null,
            'blocked_at' => null,
        ]);

        return $vendor->fresh();
    }

    /** P3.4 — add a beneficiary account (unverified until a checker confirms). */
    public function addBankAccount(Vendor $vendor, array $data): VendorBankAccount
    {
        return DB::transaction(function () use ($vendor, $data): VendorBankAccount {
            if (! empty($data['is_primary'])) {
                $vendor->bankAccounts()->update(['is_primary' => false]);
            }

            return $vendor->bankAccounts()->create([
                ...$data,
                'is_verified' => false,
            ]);
        });
    }

    /** Editing bank details drops verification — it must be re-checked. */
    public function updateBankAccount(VendorBankAccount $account, array $data): VendorBankAccount
    {
        return DB::transaction(function () use ($account, $data): VendorBankAccount {
            if (! empty($data['is_primary'])) {
                $account->vendor->bankAccounts()->where('id', '!=', $account->id)->update(['is_primary' => false]);
            }

            $account->update([...$data, 'is_verified' => false]);

            return $account->fresh();
        });
    }

    /** A checker confirms the beneficiary details are correct. */
    public function verifyBankAccount(VendorBankAccount $account): VendorBankAccount
    {
        $account->update(['is_verified' => true]);

        return $account->fresh();
    }
}
