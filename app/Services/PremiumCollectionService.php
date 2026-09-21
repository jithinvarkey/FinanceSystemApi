<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Enums\PremiumCollectionStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Models\PremiumCollection;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.17 Slice B (§8.3) — Premium collection lifecycle.
 *
 * The broker collects gross premium from the customer as fiduciary:
 * draft -> submit (maker-checker) -> post. Posting writes:
 *   Dr  bank / IBA          (funds received)
 *   Cr  customer receivable (clears the premium billed at issuance)
 * then credits the policy's `premium_collected` and, for installment policies,
 * each allocated installment's `amount_collected`.
 */
final class PremiumCollectionService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): PremiumCollection
    {
        $policy = Policy::query()->findOrFail($data['policy_id']);

        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Premium can only be collected on an issued policy.');
        }

        $allocations = $this->validatedAllocations($policy, $data['allocations'] ?? []);
        $amount = $allocations === []
            ? round((float) ($data['amount'] ?? 0), 2)
            : round(array_sum(array_column($allocations, 'amount')), 2);

        if ($amount <= 0) {
            throw new FinanceRuleException('Enter the amount collected.');
        }
        if ($amount > $policy->premiumBalanceDue() + 0.001) {
            throw new FinanceRuleException('The amount exceeds the premium still outstanding on this policy.');
        }

        return DB::transaction(function () use ($data, $policy, $allocations, $amount, $userId): PremiumCollection {
            $date = Carbon::parse($data['collection_date']);

            $collection = PremiumCollection::query()->create([
                'collection_number' => $this->numbers->next('premium_collection', $date),
                'policy_id' => $policy->id,
                'collection_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? $policy->currency_code,
                'exchange_rate' => $data['exchange_rate'] ?? $policy->exchange_rate,
                'amount' => $amount,
                'status' => PremiumCollectionStatus::Draft,
                'created_by' => $userId,
            ]);

            if ($allocations !== []) {
                $collection->allocations()->createMany($allocations);
            }

            return $collection->load(['allocations.installment', 'policy.customer', 'bankAccount']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(PremiumCollection $collection, array $data): PremiumCollection
    {
        if (! $collection->status->canSubmit()) {
            throw FinanceRuleException::notEditable($collection->status->value);
        }

        $policy = Policy::query()->findOrFail($data['policy_id']);
        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Premium can only be collected on an issued policy.');
        }

        $allocations = $this->validatedAllocations($policy, $data['allocations'] ?? []);
        $amount = $allocations === []
            ? round((float) ($data['amount'] ?? 0), 2)
            : round(array_sum(array_column($allocations, 'amount')), 2);

        if ($amount <= 0) {
            throw new FinanceRuleException('Enter the amount collected.');
        }
        if ($amount > $policy->premiumBalanceDue() + 0.001) {
            throw new FinanceRuleException('The amount exceeds the premium still outstanding on this policy.');
        }

        return DB::transaction(function () use ($collection, $data, $policy, $allocations, $amount): PremiumCollection {
            $collection->update([
                'policy_id' => $policy->id,
                'collection_date' => Carbon::parse($data['collection_date'])->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? $policy->currency_code,
                'exchange_rate' => $data['exchange_rate'] ?? $policy->exchange_rate,
                'amount' => $amount,
                'status' => PremiumCollectionStatus::Draft,
                'rejection_reason' => null,
            ]);

            $collection->allocations()->delete();
            if ($allocations !== []) {
                $collection->allocations()->createMany($allocations);
            }

            return $collection->fresh(['allocations.installment', 'policy.customer', 'bankAccount']);
        });
    }

    public function submit(PremiumCollection $collection, int $userId): PremiumCollection
    {
        if (! $collection->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($collection->status->value, PremiumCollectionStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($collection, $userId): PremiumCollection {
            $this->approvals->initiate($collection, 'premium_collection', (float) $collection->amount, $userId);
            $collection->update(['status' => PremiumCollectionStatus::PendingApproval, 'rejection_reason' => null]);

            return $collection->fresh(['allocations.installment', 'policy.customer', 'bankAccount']);
        });
    }

    public function deleteDraft(PremiumCollection $collection): void
    {
        if ($collection->status !== PremiumCollectionStatus::Draft) {
            throw FinanceRuleException::notEditable($collection->status->value);
        }

        $collection->delete();
    }

    /**
     * Post the approved collection: Dr bank → Cr customer receivable, then roll
     * the amount into the policy's `premium_collected` and each installment.
     */
    public function post(PremiumCollection $collection, int $userId): PremiumCollection
    {
        if ($collection->status !== PremiumCollectionStatus::Approved) {
            throw FinanceRuleException::invalidTransition($collection->status->value, PremiumCollectionStatus::Posted->value);
        }

        $policy = $collection->policy()->with('customer')->firstOrFail();
        $receivableId = $this->resolvePostable(
            $policy->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount(),
        );
        $bankId = $this->resolvePostable((int) $collection->bank_account_id);

        return DB::transaction(function () use ($collection, $policy, $userId, $receivableId, $bankId): PremiumCollection {
            $collection->loadMissing('allocations');
            $amount = (float) $collection->amount;
            $currency = $collection->currency_code;
            $rate = (float) $collection->exchange_rate;

            $lines = [
                new PostingLine($bankId, $amount, 0.0, $currency, $rate, null, $collection->collection_number),
                new PostingLine($receivableId, 0.0, $amount, $currency, $rate, null, 'Premium collected — '.$policy->customer->name),
            ];

            $rows = $this->posting->post($collection, Carbon::parse($collection->collection_date), $lines, $userId);

            // Roll the collection into the policy's running total.
            $policy->update(['premium_collected' => round((float) $policy->premium_collected + $amount, 2)]);

            // Credit each allocated installment.
            foreach ($collection->allocations as $allocation) {
                $installment = PolicyInstallment::query()->find($allocation->policy_installment_id);
                if ($installment === null) {
                    continue;
                }
                $collected = round((float) $installment->amount_collected + (float) $allocation->amount, 2);
                $installment->update([
                    'amount_collected' => $collected,
                    'status' => $collected >= (float) $installment->amount ? 'paid' : 'partial',
                ]);
            }

            $collection->update([
                'status' => PremiumCollectionStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $collection->fresh(['allocations.installment', 'policy.customer', 'bankAccount']);
        });
    }

    /**
     * Validate that every allocation targets an installment of this policy and
     * does not exceed its outstanding balance.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return list<array{policy_installment_id: int, amount: float}>
     *
     * @throws FinanceRuleException
     */
    private function validatedAllocations(Policy $policy, array $raw): array
    {
        if ($raw === []) {
            return [];
        }

        $installments = PolicyInstallment::query()
            ->whereIn('id', array_column($raw, 'policy_installment_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($raw as $row) {
            $installment = $installments->get((int) $row['policy_installment_id']);
            $amount = round((float) $row['amount'], 2);

            if ($installment === null || (int) $installment->policy_id !== (int) $policy->id) {
                throw new FinanceRuleException('An allocated installment does not belong to this policy.');
            }
            if ($amount <= 0 || $amount > $installment->balanceDue() + 0.001) {
                throw new FinanceRuleException("Allocation for installment #{$installment->sequence} exceeds its outstanding balance.");
            }

            $out[] = ['policy_installment_id' => $installment->id, 'amount' => $amount];
        }

        return $out;
    }
}
