<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InsurerSettlementStatus;
use App\Enums\PolicyStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\InsurerSettlement;
use App\Models\Policy;
use App\Models\Vendor;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.17 Slice B (§8.4) — Insurer settlement lifecycle.
 *
 * The broker remits the net premium owed to an insurer (gross less commission
 * incl. its VAT, already withheld at issuance) across one or more issued
 * policies: draft -> submit (maker-checker) -> post. Posting writes:
 *   Dr  insurer payable (clears the premium control booked at issuance)
 *   Cr  bank / IBA       (funds remitted)
 * then credits each policy's `insurer_settled` running total.
 */
final class InsurerSettlementService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): InsurerSettlement
    {
        $insurer = Vendor::query()->findOrFail($data['insurer_id']);
        $allocations = $this->validatedAllocations($insurer->id, $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($data, $insurer, $allocations, $amount, $userId): InsurerSettlement {
            $date = Carbon::parse($data['settlement_date']);

            $settlement = InsurerSettlement::query()->create([
                'settlement_number' => $this->numbers->next('insurer_settlement', $date),
                'insurer_id' => $insurer->id,
                'settlement_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => InsurerSettlementStatus::Draft,
                'created_by' => $userId,
            ]);

            $settlement->allocations()->createMany($allocations);

            return $settlement->load(['allocations.policy', 'insurer', 'bankAccount']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(InsurerSettlement $settlement, array $data): InsurerSettlement
    {
        if (! $settlement->status->canSubmit()) {
            throw FinanceRuleException::notEditable($settlement->status->value);
        }

        $insurer = Vendor::query()->findOrFail($data['insurer_id']);
        $allocations = $this->validatedAllocations($insurer->id, $data['allocations'] ?? []);
        $amount = round(array_sum(array_column($allocations, 'amount')), 2);

        return DB::transaction(function () use ($settlement, $data, $insurer, $allocations, $amount): InsurerSettlement {
            $settlement->update([
                'insurer_id' => $insurer->id,
                'settlement_date' => Carbon::parse($data['settlement_date'])->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'amount' => $amount,
                'status' => InsurerSettlementStatus::Draft,
                'rejection_reason' => null,
            ]);

            $settlement->allocations()->delete();
            $settlement->allocations()->createMany($allocations);

            return $settlement->fresh(['allocations.policy', 'insurer', 'bankAccount']);
        });
    }

    public function submit(InsurerSettlement $settlement, int $userId): InsurerSettlement
    {
        if (! $settlement->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($settlement->status->value, InsurerSettlementStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($settlement, $userId): InsurerSettlement {
            $this->approvals->initiate($settlement, 'insurer_settlement', (float) $settlement->amount, $userId);
            $settlement->update(['status' => InsurerSettlementStatus::PendingApproval, 'rejection_reason' => null]);

            return $settlement->fresh(['allocations.policy', 'insurer', 'bankAccount']);
        });
    }

    public function deleteDraft(InsurerSettlement $settlement): void
    {
        if ($settlement->status !== InsurerSettlementStatus::Draft) {
            throw FinanceRuleException::notEditable($settlement->status->value);
        }

        $settlement->delete();
    }

    /**
     * Post the approved settlement: Dr insurer payable → Cr bank, then roll the
     * amount into each allocated policy's `insurer_settled`.
     */
    public function post(InsurerSettlement $settlement, int $userId): InsurerSettlement
    {
        if ($settlement->status !== InsurerSettlementStatus::Approved) {
            throw FinanceRuleException::invalidTransition($settlement->status->value, InsurerSettlementStatus::Posted->value);
        }

        $insurer = $settlement->insurer()->firstOrFail();
        $payableId = $this->resolvePostable(
            $insurer->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount(),
        );
        $bankId = $this->resolvePostable((int) $settlement->bank_account_id);

        return DB::transaction(function () use ($settlement, $insurer, $userId, $payableId, $bankId): InsurerSettlement {
            $settlement->loadMissing('allocations');
            $amount = (float) $settlement->amount;
            $currency = $settlement->currency_code;
            $rate = (float) $settlement->exchange_rate;

            $lines = [
                new PostingLine($payableId, $amount, 0.0, $currency, $rate, null, 'Settlement to '.$insurer->name),
                new PostingLine($bankId, 0.0, $amount, $currency, $rate, null, $settlement->settlement_number),
            ];

            $rows = $this->posting->post($settlement, Carbon::parse($settlement->settlement_date), $lines, $userId);

            // Roll the remittance into each policy's running total.
            foreach ($settlement->allocations as $allocation) {
                $policy = Policy::query()->find($allocation->policy_id);
                if ($policy === null) {
                    continue;
                }
                $policy->update(['insurer_settled' => round((float) $policy->insurer_settled + (float) $allocation->amount, 2)]);
            }

            $settlement->update([
                'status' => InsurerSettlementStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $settlement->fresh(['allocations.policy', 'insurer', 'bankAccount']);
        });
    }

    /**
     * Validate that every allocation targets an issued policy of this insurer and
     * does not exceed its outstanding net-due-to-insurer balance.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return list<array{policy_id: int, amount: float}>
     *
     * @throws FinanceRuleException
     */
    private function validatedAllocations(int $insurerId, array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('Select at least one policy to settle.');
        }

        $policies = Policy::query()
            ->whereIn('id', array_column($raw, 'policy_id'))
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($raw as $row) {
            $policy = $policies->get((int) $row['policy_id']);
            $amount = round((float) $row['amount'], 2);

            if ($policy === null || (int) $policy->insurer_id !== $insurerId) {
                throw new FinanceRuleException('An allocated policy does not belong to this insurer.');
            }
            if ($policy->status !== PolicyStatus::Issued) {
                throw new FinanceRuleException("Policy {$policy->policy_number} is not issued and cannot be settled.");
            }
            if ($amount <= 0 || $amount > $policy->insurerBalanceDue() + 0.001) {
                throw new FinanceRuleException("Allocation for {$policy->policy_number} exceeds the net premium still owed to the insurer.");
            }

            $out[] = ['policy_id' => $policy->id, 'amount' => $amount];
        }

        return $out;
    }
}
