<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CancellationMethod;
use App\Enums\CancellationStatus;
use App\Enums\PolicyStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Policy;
use App\Models\PolicyCancellation;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.17 Slice C (§10) — Policy cancellation lifecycle.
 *
 * Splits the premium into the period on risk (earned, kept by the insurer) and
 * the cancelled period (unearned, refunded). draft -> submit (maker-checker) ->
 * post. Posting reverses the unearned slice of the issuance entry:
 *   Dr insurer payable / Cr customer receivable           (unearned gross — refund)
 *   Dr commission revenue + Dr output VAT / Cr insurer payable  (commission clawback)
 * then flips the policy to cancelled and rolls its totals down to the earned part.
 */
final class PolicyCancellationService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(Policy $policy, array $data, int $userId): PolicyCancellation
    {
        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Only an issued policy can be cancelled.');
        }

        $method = CancellationMethod::from($data['method']);
        $cancelDate = Carbon::parse($data['cancellation_date']);
        $figures = $this->computeRefund($policy, $method, $cancelDate, $data['short_rate_penalty'] ?? null);

        return DB::transaction(function () use ($policy, $data, $method, $cancelDate, $figures, $userId): PolicyCancellation {
            return PolicyCancellation::query()->create([
                'cancellation_number' => $this->numbers->next('policy_cancellation', $cancelDate),
                'policy_id' => $policy->id,
                'method' => $method,
                'cancellation_date' => $cancelDate->toDateString(),
                'reason' => $data['reason'] ?? null,
                ...$figures,
                'status' => CancellationStatus::Draft,
                'created_by' => $userId,
            ])->load('policy');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(PolicyCancellation $cancellation, array $data): PolicyCancellation
    {
        if (! $cancellation->status->canSubmit()) {
            throw FinanceRuleException::notEditable($cancellation->status->value);
        }

        $policy = $cancellation->policy()->firstOrFail();
        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Only an issued policy can be cancelled.');
        }

        $method = CancellationMethod::from($data['method']);
        $cancelDate = Carbon::parse($data['cancellation_date']);
        $figures = $this->computeRefund($policy, $method, $cancelDate, $data['short_rate_penalty'] ?? null);

        $cancellation->update([
            'method' => $method,
            'cancellation_date' => $cancelDate->toDateString(),
            'reason' => $data['reason'] ?? null,
            ...$figures,
            'status' => CancellationStatus::Draft,
            'rejection_reason' => null,
        ]);

        return $cancellation->fresh('policy');
    }

    public function submit(PolicyCancellation $cancellation, int $userId): PolicyCancellation
    {
        if (! $cancellation->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($cancellation->status->value, CancellationStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($cancellation, $userId): PolicyCancellation {
            $this->approvals->initiate($cancellation, 'policy_cancellation', (float) $cancellation->refund_gross, $userId);
            $cancellation->update(['status' => CancellationStatus::PendingApproval, 'rejection_reason' => null]);

            return $cancellation->fresh('policy');
        });
    }

    public function deleteDraft(PolicyCancellation $cancellation): void
    {
        if ($cancellation->status !== CancellationStatus::Draft) {
            throw FinanceRuleException::notEditable($cancellation->status->value);
        }

        $cancellation->delete();
    }

    /**
     * Post the approved cancellation: refund the unearned premium and claw back
     * the matching commission, then cancel the policy.
     */
    public function post(PolicyCancellation $cancellation, int $userId): PolicyCancellation
    {
        if ($cancellation->status !== CancellationStatus::Approved) {
            throw FinanceRuleException::invalidTransition($cancellation->status->value, CancellationStatus::Posted->value);
        }

        $policy = $cancellation->policy()->with(['customer', 'insurer', 'product'])->firstOrFail();

        $receivableId = $this->resolvePostable(
            $policy->customer->default_receivable_account_id ?? throw FinanceRuleException::noReceivableAccount(),
        );
        $insurerPayableId = $this->resolvePostable(
            $policy->insurer->default_payable_account_id ?? throw FinanceRuleException::noPayableAccount(),
        );
        $commissionRevenueId = $this->resolvePostable(
            $policy->product->commission_revenue_account_id
                ?? throw new FinanceRuleException('Configure a commission revenue account on the product first.'),
        );

        $refundGross = (float) $cancellation->refund_gross;
        $clawComm = (float) $cancellation->clawback_commission;
        $clawCommTax = (float) $cancellation->clawback_commission_tax;
        $currency = $policy->currency_code;
        $rate = (float) $policy->exchange_rate;

        return DB::transaction(function () use ($cancellation, $policy, $userId, $receivableId, $insurerPayableId, $commissionRevenueId, $refundGross, $clawComm, $clawCommTax, $currency, $rate): PolicyCancellation {
            $lines = [
                // Refund the unearned premium: clear insurer payable, credit the customer.
                new PostingLine($insurerPayableId, $refundGross, 0.0, $currency, $rate, null, 'Cancellation refund — '.$policy->insurer->name),
                new PostingLine($receivableId, 0.0, $refundGross, $currency, $rate, null, 'Premium refund — '.$policy->customer->name),
                // Claw back the commission earned on the unearned slice.
                new PostingLine($commissionRevenueId, $clawComm, 0.0, $currency, $rate, null, 'Commission clawback '.$cancellation->cancellation_number),
                new PostingLine($insurerPayableId, 0.0, round($clawComm + $clawCommTax, 2), $currency, $rate, null, 'Commission clawback '.$cancellation->cancellation_number),
            ];

            if ($clawCommTax > 0) {
                $outputVatId = $this->resolvePostable($this->commissionVatAccount($policy));
                $lines[] = new PostingLine($outputVatId, $clawCommTax, 0.0, $currency, $rate, null, 'Output VAT clawback');
            }

            $rows = $this->posting->post($cancellation, Carbon::parse($cancellation->cancellation_date), $lines, $userId);

            $cancellation->update([
                'status' => CancellationStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            // Roll the refunded slice out of the policy and cancel it.
            $policy->update([
                'net_premium' => round((float) $policy->net_premium - (float) $cancellation->refund_net, 2),
                'premium_tax_amount' => round((float) $policy->premium_tax_amount - (float) $cancellation->refund_premium_tax, 2),
                'gross_premium' => round((float) $policy->gross_premium - $refundGross, 2),
                'commission_amount' => round((float) $policy->commission_amount - $clawComm, 2),
                'commission_tax_amount' => round((float) $policy->commission_tax_amount - $clawCommTax, 2),
                'status' => PolicyStatus::Cancelled,
            ]);

            // Drop any not-yet-collected installments.
            $policy->installments()
                ->whereColumn('amount_collected', '<', 'amount')
                ->update(['status' => 'cancelled']);

            return $cancellation->fresh('policy');
        });
    }

    // ----- internals -----

    /**
     * Compute the earned/unearned split and the refund + clawback figures.
     *
     * @return array{policy_days: int, days_on_risk: int, short_rate_penalty: float|null, refund_net: float, refund_premium_tax: float, refund_gross: float, clawback_commission: float, clawback_commission_tax: float}
     */
    private function computeRefund(Policy $policy, CancellationMethod $method, Carbon $cancelDate, float|int|string|null $penaltyInput): array
    {
        $start = Carbon::parse($policy->start_date);
        $end = Carbon::parse($policy->end_date);

        $policyDays = $start->diffInDays($end);
        if ($policyDays <= 0) {
            throw new FinanceRuleException('The policy term is invalid; cannot compute a cancellation refund.');
        }

        $daysOnRisk = (int) max(0, min($policyDays, $start->diffInDays($cancelDate, false)));
        $unexpiredDays = $policyDays - $daysOnRisk;

        $net = (float) $policy->net_premium;
        $proRataUnearnedNet = round($net * $unexpiredDays / $policyDays, 2);

        $penalty = null;
        $refundNet = $proRataUnearnedNet;
        if ($method === CancellationMethod::ShortRate) {
            $penalty = round((float) ($penaltyInput ?? 0), 2);
            if ($penalty < 0 || $penalty > 100) {
                throw new FinanceRuleException('The short-rate penalty must be between 0 and 100 percent.');
            }
            $refundNet = round($proRataUnearnedNet * (1 - $penalty / 100), 2);
        }

        // Scale VAT and commission by the refunded fraction of the net premium.
        $fraction = $net > 0 ? $refundNet / $net : 0.0;

        return [
            'policy_days' => $policyDays,
            'days_on_risk' => $daysOnRisk,
            'short_rate_penalty' => $penalty,
            'refund_net' => $refundNet,
            'refund_premium_tax' => round((float) $policy->premium_tax_amount * $fraction, 2),
            'refund_gross' => round($refundNet + (float) $policy->premium_tax_amount * $fraction, 2),
            'clawback_commission' => round((float) $policy->commission_amount * $fraction, 2),
            'clawback_commission_tax' => round((float) $policy->commission_tax_amount * $fraction, 2),
        ];
    }

    private function commissionVatAccount(Policy $policy): int
    {
        $accountId = TaxCode::query()->whereKey($policy->tax_code_id)->value('output_account_id');

        return (int) ($accountId ?? throw new FinanceRuleException('The policy tax code has no output VAT account configured.'));
    }
}
