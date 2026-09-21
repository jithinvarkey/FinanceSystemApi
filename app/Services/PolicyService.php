<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Policy;
use App\Models\Product;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P4.17 — Policy lifecycle and the broker fiduciary posting.
 *
 * Draft -> submit (maker-checker approval) -> issue. Issuance posts the dual
 * entry through the single GL gateway (see the broker design doc §8):
 *   1. Dr customer receivable (gross premium)   Cr insurer payable (gross premium)
 *   2. Dr insurer payable (commission + its VAT) Cr commission revenue (net)
 *                                                Cr output VAT (broker's commission VAT)
 *
 * Premium is the insurer's money — never the broker's revenue. The broker's
 * P&L recognises only the commission. Life business (product VAT-exempt) posts
 * no VAT legs either side.
 */
final class PolicyService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): Policy
    {
        $product = Product::query()->findOrFail($data['product_id']);
        $amounts = $this->deriveAmounts($product, (float) $data['net_premium'], $data['commission_rate'] ?? null);

        return DB::transaction(function () use ($data, $product, $amounts, $userId): Policy {
            $start = Carbon::parse($data['start_date']);

            $policy = Policy::query()->create([
                'policy_number' => $this->numbers->next('policy', $start),
                'insurer_policy_no' => $data['insurer_policy_no'] ?? null,
                'quote_number' => $data['quote_number'] ?? null,
                'customer_id' => $data['customer_id'],
                'insurer_id' => $data['insurer_id'],
                'product_id' => $product->id,
                'start_date' => $start->toDateString(),
                'end_date' => $data['end_date'],
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'payment_method' => $data['payment_method'] ?? 'full',
                'tax_code_id' => $product->default_tax_code_id,
                ...$amounts,
                'status' => PolicyStatus::Draft,
                'created_by' => $userId,
            ]);

            $this->syncInstallments($policy, $data);

            return $policy->load(['customer', 'insurer', 'product', 'installments']);
        });
    }

    /**
     * P4.17 Slice E (§11) — renew an expiring policy: clone it into a new draft
     * for the next term, linked back via `renewed_from_policy_id`, with an
     * (often lower) renewal commission rate. Then it follows the normal
     * submit -> approve -> issue flow.
     *
     * @param array<string, mixed> $data
     */
    public function renew(Policy $expiring, array $data, int $userId): Policy
    {
        if (! in_array($expiring->status, [PolicyStatus::Issued, PolicyStatus::Expired], true)) {
            throw new FinanceRuleException('Only an issued or expired policy can be renewed.');
        }
        if ($expiring->renewal()->exists()) {
            throw new FinanceRuleException('This policy has already been renewed.');
        }

        $product = Product::query()->findOrFail($data['product_id'] ?? $expiring->product_id);
        $net = (float) ($data['net_premium'] ?? $expiring->net_premium);
        $amounts = $this->deriveAmounts($product, $net, $data['commission_rate'] ?? $expiring->commission_rate);

        $start = isset($data['start_date'])
            ? Carbon::parse($data['start_date'])
            : Carbon::parse($expiring->end_date)->addDay();
        $end = isset($data['end_date'])
            ? Carbon::parse($data['end_date'])->toDateString()
            : $start->copy()->addYear()->subDay()->toDateString();

        return DB::transaction(function () use ($expiring, $data, $product, $amounts, $start, $end, $userId): Policy {
            $policy = Policy::query()->create([
                'policy_number' => $this->numbers->next('policy', $start),
                'insurer_policy_no' => $data['insurer_policy_no'] ?? null,
                'quote_number' => $data['quote_number'] ?? null,
                'customer_id' => $expiring->customer_id,
                'insurer_id' => $expiring->insurer_id,
                'product_id' => $product->id,
                'renewed_from_policy_id' => $expiring->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end,
                'currency_code' => $expiring->currency_code,
                'exchange_rate' => $expiring->exchange_rate,
                'payment_method' => $data['payment_method'] ?? $expiring->payment_method,
                'tax_code_id' => $product->default_tax_code_id,
                ...$amounts,
                'status' => PolicyStatus::Draft,
                'created_by' => $userId,
            ]);

            $this->syncInstallments($policy, $data);

            return $policy->load(['customer', 'insurer', 'product', 'installments', 'renewedFrom']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Policy $policy, array $data): Policy
    {
        if (! $policy->status->isEditable()) {
            throw FinanceRuleException::notEditable($policy->status->value);
        }

        $product = Product::query()->findOrFail($data['product_id'] ?? $policy->product_id);
        $amounts = $this->deriveAmounts($product, (float) $data['net_premium'], $data['commission_rate'] ?? null);

        return DB::transaction(function () use ($policy, $data, $product, $amounts): Policy {
            $policy->update([
                'insurer_policy_no' => $data['insurer_policy_no'] ?? null,
                'quote_number' => $data['quote_number'] ?? null,
                'customer_id' => $data['customer_id'],
                'insurer_id' => $data['insurer_id'],
                'product_id' => $product->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'currency_code' => $data['currency_code'] ?? $policy->currency_code,
                'payment_method' => $data['payment_method'] ?? $policy->payment_method,
                'tax_code_id' => $product->default_tax_code_id,
                ...$amounts,
                'status' => PolicyStatus::Draft,
                'rejection_reason' => null,
            ]);

            $this->syncInstallments($policy, $data);

            return $policy->fresh(['customer', 'insurer', 'product', 'installments']);
        });
    }

    /** Send the policy for maker-checker approval (routed on gross premium). */
    public function submit(Policy $policy, int $userId): Policy
    {
        if (! $policy->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($policy->status->value, PolicyStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($policy, $userId): Policy {
            $this->approvals->initiate($policy, 'policy', (float) $policy->gross_premium, $userId);
            $policy->update(['status' => PolicyStatus::PendingApproval, 'rejection_reason' => null]);

            return $policy->fresh(['customer', 'insurer', 'product']);
        });
    }

    public function deleteDraft(Policy $policy): void
    {
        if ($policy->status !== PolicyStatus::Draft) {
            throw FinanceRuleException::notEditable($policy->status->value);
        }

        $policy->delete();
    }

    /**
     * Issue the approved policy: post the fiduciary dual entry to the GL.
     */
    public function issue(Policy $policy, int $userId): Policy
    {
        if ($policy->status !== PolicyStatus::Approved) {
            throw FinanceRuleException::invalidTransition($policy->status->value, PolicyStatus::Issued->value);
        }

        $policy->loadMissing(['customer', 'insurer', 'product']);

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

        $gross = (float) $policy->gross_premium;
        $commission = (float) $policy->commission_amount;
        $commissionTax = (float) $policy->commission_tax_amount;
        $commissionGross = round($commission + $commissionTax, 2);
        $currency = $policy->currency_code;
        $rate = (float) $policy->exchange_rate;

        return DB::transaction(function () use ($policy, $userId, $receivableId, $insurerPayableId, $commissionRevenueId, $gross, $commission, $commissionTax, $commissionGross, $currency, $rate): Policy {
            $lines = [
                // 1. Premium billing — customer owes gross; we owe the insurer the same.
                new PostingLine($receivableId, $gross, 0.0, $currency, $rate, null, 'Premium — '.$policy->customer->name),
                new PostingLine($insurerPayableId, 0.0, $gross, $currency, $rate, null, 'Premium payable — '.$policy->insurer->name),
                // 2. Commission earned — netted against the insurer payable.
                new PostingLine($insurerPayableId, $commissionGross, 0.0, $currency, $rate, null, 'Commission '.$policy->policy_number),
                new PostingLine($commissionRevenueId, 0.0, $commission, $currency, $rate, null, 'Brokerage '.$policy->policy_number),
            ];

            // Broker's output VAT on commission (skipped for life-exempt — tax = 0).
            if ($commissionTax > 0) {
                $outputVatId = $this->resolvePostable($this->commissionVatAccount($policy));
                $lines[] = new PostingLine($outputVatId, 0.0, $commissionTax, $currency, $rate, null, 'Output VAT on commission');
            }

            $rows = $this->posting->post($policy, Carbon::parse($policy->start_date), $lines, $userId);

            $policy->update([
                'status' => PolicyStatus::Issued,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'issued_by' => $userId,
                'issued_at' => now(),
            ]);

            return $policy->fresh(['customer', 'insurer', 'product']);
        });
    }

    // ----- internals -----

    /**
     * (Re)build the installment schedule for a policy. Full-payment policies
     * carry none; installment policies split the gross premium across
     * `installment_count` equal parts (the last absorbs rounding), due monthly
     * from the start date.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncInstallments(Policy $policy, array $data): void
    {
        $policy->installments()->delete();

        if (($policy->payment_method ?? 'full') !== 'installment') {
            return;
        }

        $count = max(2, min(60, (int) ($data['installment_count'] ?? 2)));
        $policy->installments()->createMany(
            $this->buildSchedule((float) $policy->gross_premium, $count, Carbon::parse($policy->start_date)),
        );
    }

    /**
     * Re-derive the installment amounts after the gross premium changes (e.g. an
     * endorsement). Keeps the same number of installments and due dates and
     * preserves any `amount_collected`, only re-spreading the new gross and
     * recomputing each row's status. No-op for full-payment policies.
     */
    public function resyncInstallmentSchedule(Policy $policy): void
    {
        if (($policy->payment_method ?? 'full') !== 'installment') {
            return;
        }

        $existing = $policy->installments()->orderBy('sequence')->get();
        if ($existing->isEmpty()) {
            return;
        }

        $schedule = $this->buildSchedule(
            (float) $policy->gross_premium,
            $existing->count(),
            Carbon::parse($policy->start_date),
        );

        DB::transaction(function () use ($existing, $schedule): void {
            foreach ($existing->values() as $i => $installment) {
                $amount = (float) $schedule[$i]['amount'];
                $collected = (float) $installment->amount_collected;

                $installment->update([
                    'amount' => $amount,
                    'status' => $collected <= 0 ? 'unpaid' : ($collected >= $amount ? 'paid' : 'partial'),
                ]);
            }
        });
    }

    /**
     * @return list<array{sequence: int, due_date: string, amount: float, amount_collected: int, status: string}>
     */
    private function buildSchedule(float $gross, int $count, Carbon $start): array
    {
        $base = floor($gross / $count * 100) / 100; // round down so the last row absorbs the remainder
        $rows = [];
        $allocated = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $amount = $i === $count - 1 ? round($gross - $allocated, 2) : $base;
            $allocated = round($allocated + $amount, 2);

            $rows[] = [
                'sequence' => $i + 1,
                'due_date' => $start->copy()->addMonths($i)->toDateString(),
                'amount' => $amount,
                'amount_collected' => 0,
                'status' => 'unpaid',
            ];
        }

        return $rows;
    }

    /**
     * Derive premium VAT, gross, commission and commission VAT from the product.
     * Life (or no-tax-code) products are VAT-exempt: both VAT amounts are zero.
     *
     * @return array{net_premium: float, premium_tax_amount: float, gross_premium: float, commission_rate: float, commission_amount: float, commission_tax_amount: float}
     */
    private function deriveAmounts(Product $product, float $netPremium, float|int|string|null $commissionRateOverride): array
    {
        $net = round($netPremium, 2);
        $vatRate = $this->taxRate($product->default_tax_code_id);
        $commissionRate = round((float) ($commissionRateOverride ?? $product->default_commission_rate), 4);

        $premiumTax = round($net * $vatRate / 100, 2);
        $commission = round($net * $commissionRate / 100, 2);
        $commissionTax = round($commission * $vatRate / 100, 2);

        return [
            'net_premium' => $net,
            'premium_tax_amount' => $premiumTax,
            'gross_premium' => round($net + $premiumTax, 2),
            'commission_rate' => $commissionRate,
            'commission_amount' => $commission,
            'commission_tax_amount' => $commissionTax,
        ];
    }

    private function taxRate(?int $taxCodeId): float
    {
        if ($taxCodeId === null) {
            return 0.0;
        }

        return (float) (TaxCode::query()->whereKey($taxCodeId)->value('rate') ?? 0);
    }

    /** The output-VAT account for the policy's (commission) tax code. */
    private function commissionVatAccount(Policy $policy): int
    {
        $accountId = TaxCode::query()->whereKey($policy->tax_code_id)->value('output_account_id');

        return (int) ($accountId ?? throw new FinanceRuleException('The policy tax code has no output VAT account configured.'));
    }
}
