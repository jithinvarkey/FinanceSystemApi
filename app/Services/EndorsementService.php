<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EndorsementStatus;
use App\Enums\EndorsementType;
use App\Enums\PolicyStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Policy;
use App\Models\PolicyEndorsement;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.17 — Policy endorsements (mid-term financial changes).
 *
 * Four types: addition & upgrade raise an ADDITIONAL premium; deletion &
 * downgrade raise a REFUND. Posting writes a *delta* of the policy's fiduciary
 * entry — same direction for additional, reversed for refund — and then adjusts
 * the policy's running totals. VAT and commission deltas derive from the
 * policy's own tax code and commission rate (so life stays exempt).
 */
final class EndorsementService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
        private readonly PolicyService $policies,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(Policy $policy, array $data, int $userId): PolicyEndorsement
    {
        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Only an issued policy can be endorsed.');
        }

        $type = EndorsementType::from($data['type']);
        $deltas = $this->deriveDeltas($policy, (float) $data['delta_net_premium']);

        return DB::transaction(function () use ($policy, $data, $type, $deltas, $userId): PolicyEndorsement {
            $effective = Carbon::parse($data['effective_date']);

            return $policy->endorsements()->create([
                'endorsement_number' => $this->numbers->next('endorsement', $effective),
                'type' => $type,
                'direction' => $type->direction(),
                'effective_date' => $effective->toDateString(),
                'reason' => $data['reason'] ?? null,
                ...$deltas,
                'status' => EndorsementStatus::Draft,
                'created_by' => $userId,
            ])->load('policy');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(PolicyEndorsement $endorsement, array $data): PolicyEndorsement
    {
        if (! $endorsement->status->canSubmit()) {
            throw FinanceRuleException::notEditable($endorsement->status->value);
        }

        $policy = $endorsement->policy()->firstOrFail();
        if ($policy->status !== PolicyStatus::Issued) {
            throw new FinanceRuleException('Only an issued policy can be endorsed.');
        }

        $type = EndorsementType::from($data['type']);
        $deltas = $this->deriveDeltas($policy, (float) $data['delta_net_premium']);

        $endorsement->update([
            'type' => $type,
            'direction' => $type->direction(),
            'effective_date' => Carbon::parse($data['effective_date'])->toDateString(),
            'reason' => $data['reason'] ?? null,
            ...$deltas,
            'status' => EndorsementStatus::Draft,
            'rejection_reason' => null,
        ]);

        return $endorsement->fresh('policy');
    }

    public function submit(PolicyEndorsement $endorsement, int $userId): PolicyEndorsement
    {
        if (! $endorsement->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($endorsement->status->value, EndorsementStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($endorsement, $userId): PolicyEndorsement {
            $this->approvals->initiate($endorsement, 'endorsement', (float) $endorsement->delta_gross, $userId);
            $endorsement->update(['status' => EndorsementStatus::PendingApproval, 'rejection_reason' => null]);

            return $endorsement->fresh('policy');
        });
    }

    public function deleteDraft(PolicyEndorsement $endorsement): void
    {
        if ($endorsement->status !== EndorsementStatus::Draft) {
            throw FinanceRuleException::notEditable($endorsement->status->value);
        }

        $endorsement->delete();
    }

    /**
     * Post the approved endorsement: a delta of the policy's fiduciary entry,
     * then adjust the policy's running totals.
     */
    public function post(PolicyEndorsement $endorsement, int $userId): PolicyEndorsement
    {
        if ($endorsement->status !== EndorsementStatus::Approved) {
            throw FinanceRuleException::invalidTransition($endorsement->status->value, EndorsementStatus::Posted->value);
        }

        $policy = $endorsement->policy()->with(['customer', 'insurer', 'product'])->firstOrFail();

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

        $gross = (float) $endorsement->delta_gross;
        $commission = (float) $endorsement->delta_commission;
        $commissionTax = (float) $endorsement->delta_commission_tax;
        $commissionGross = round($commission + $commissionTax, 2);
        $additional = $endorsement->type->isAdditional();
        $currency = $policy->currency_code;
        $rate = (float) $policy->exchange_rate;

        // Debit/credit helpers — flipped for a refund.
        $dr = fn (int $acct, float $amt, string $desc) => $additional
            ? new PostingLine($acct, $amt, 0.0, $currency, $rate, null, $desc)
            : new PostingLine($acct, 0.0, $amt, $currency, $rate, null, $desc);
        $cr = fn (int $acct, float $amt, string $desc) => $additional
            ? new PostingLine($acct, 0.0, $amt, $currency, $rate, null, $desc)
            : new PostingLine($acct, $amt, 0.0, $currency, $rate, null, $desc);

        $verb = $additional ? 'Additional premium' : 'Refund premium';

        return DB::transaction(function () use ($endorsement, $policy, $userId, $receivableId, $insurerPayableId, $commissionRevenueId, $gross, $commission, $commissionTax, $commissionGross, $additional, $currency, $rate, $dr, $cr, $verb): PolicyEndorsement {
            // Mirrors the issuance entry (PolicyService::issue), as a delta:
            //   Dr AR (gross) / Cr insurer payable (gross)
            //   Dr insurer payable (commission+VAT) / Cr commission revenue + Cr output VAT
            // For a refund, $dr/$cr are flipped so the whole entry reverses.
            $lines = [
                $dr($receivableId, $gross, $verb.' — '.$policy->customer->name),
                $cr($insurerPayableId, $gross, $verb.' payable — '.$policy->insurer->name),
                $dr($insurerPayableId, $commissionGross, 'Commission '.$endorsement->endorsement_number),
                $cr($commissionRevenueId, $commission, 'Brokerage '.$endorsement->endorsement_number),
            ];

            if ($commissionTax > 0) {
                $outputVatId = $this->resolvePostable($this->commissionVatAccount($policy));
                $lines[] = $cr($outputVatId, $commissionTax, 'Output VAT on commission');
            }

            $rows = $this->posting->post($endorsement, Carbon::parse($endorsement->effective_date), $lines, $userId);

            $endorsement->update([
                'status' => EndorsementStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            // Roll the delta into the policy's running totals.
            $sign = $additional ? 1 : -1;
            $policy->update([
                'net_premium' => round((float) $policy->net_premium + $sign * (float) $endorsement->delta_net_premium, 2),
                'premium_tax_amount' => round((float) $policy->premium_tax_amount + $sign * (float) $endorsement->delta_premium_tax, 2),
                'gross_premium' => round((float) $policy->gross_premium + $sign * $gross, 2),
                'commission_amount' => round((float) $policy->commission_amount + $sign * $commission, 2),
                'commission_tax_amount' => round((float) $policy->commission_tax_amount + $sign * $commissionTax, 2),
            ]);

            // Keep the installment schedule consistent with the new gross premium.
            $this->policies->resyncInstallmentSchedule($policy);

            return $endorsement->fresh('policy');
        });
    }

    // ----- internals -----

    /**
     * Derive the VAT and commission deltas from the policy's tax code and rate.
     *
     * @return array{delta_net_premium: float, delta_premium_tax: float, delta_gross: float, delta_commission: float, delta_commission_tax: float}
     */
    private function deriveDeltas(Policy $policy, float $deltaNet): array
    {
        $net = round(abs($deltaNet), 2);
        if ($net <= 0) {
            throw new FinanceRuleException('Enter the premium change amount for this endorsement.');
        }

        $vatRate = $this->taxRate($policy->tax_code_id);
        $commRate = (float) $policy->commission_rate;

        $premiumTax = round($net * $vatRate / 100, 2);
        $commission = round($net * $commRate / 100, 2);
        $commissionTax = round($commission * $vatRate / 100, 2);

        return [
            'delta_net_premium' => $net,
            'delta_premium_tax' => $premiumTax,
            'delta_gross' => round($net + $premiumTax, 2),
            'delta_commission' => $commission,
            'delta_commission_tax' => $commissionTax,
        ];
    }

    private function taxRate(?int $taxCodeId): float
    {
        if ($taxCodeId === null) {
            return 0.0;
        }

        return (float) (TaxCode::query()->whereKey($taxCodeId)->value('rate') ?? 0);
    }

    private function commissionVatAccount(Policy $policy): int
    {
        $accountId = TaxCode::query()->whereKey($policy->tax_code_id)->value('output_account_id');

        return (int) ($accountId ?? throw new FinanceRuleException('The policy tax code has no output VAT account configured.'));
    }
}
