<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseClaimStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ExpenseClaim;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P6 — Expense claim lifecycle. Draft (VAT 15% per line) → submit (maker-checker)
 * → post. Posting writes, through the single GL gateway:
 *   Dr  expense lines (net)
 *   Dr  recoverable input VAT
 *   Cr  the pay-from account (bank / cash / payable), gross total.
 */
final class ExpenseClaimService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): ExpenseClaim
    {
        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($data, $lines, $totals, $userId): ExpenseClaim {
            $date = Carbon::parse($data['expense_date']);

            $claim = ExpenseClaim::query()->create([
                'claim_number' => $this->numbers->next('expense_claim', $date),
                'claimant' => $data['claimant'],
                'expense_date' => $date->toDateString(),
                'credit_account_id' => $data['credit_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => ExpenseClaimStatus::Draft,
                'created_by' => $userId,
            ]);

            $claim->lines()->createMany($lines);

            return $claim->load('lines.account');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(ExpenseClaim $claim, array $data): ExpenseClaim
    {
        if (! $claim->status->isEditable()) {
            throw FinanceRuleException::notEditable($claim->status->value);
        }

        $lines = $this->normaliseLines($data['lines'] ?? []);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($claim, $data, $lines, $totals): ExpenseClaim {
            $claim->update([
                'claimant' => $data['claimant'],
                'expense_date' => $data['expense_date'],
                'credit_account_id' => $data['credit_account_id'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'currency_code' => $data['currency_code'] ?? $claim->currency_code,
                'exchange_rate' => $data['exchange_rate'] ?? $claim->exchange_rate,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'status' => ExpenseClaimStatus::Draft,
                'rejection_reason' => null,
            ]);

            $claim->lines()->delete();
            $claim->lines()->createMany($lines);

            return $claim->fresh('lines.account');
        });
    }

    public function deleteDraft(ExpenseClaim $claim): void
    {
        if ($claim->status !== ExpenseClaimStatus::Draft) {
            throw FinanceRuleException::notEditable($claim->status->value);
        }

        $claim->delete();
    }

    public function submit(ExpenseClaim $claim, int $userId): ExpenseClaim
    {
        if (! $claim->status->canSubmit()) {
            throw FinanceRuleException::invalidTransition($claim->status->value, ExpenseClaimStatus::PendingApproval->value);
        }

        return DB::transaction(function () use ($claim, $userId): ExpenseClaim {
            $this->approvals->initiate($claim, 'expense_claim', (float) $claim->total_amount, $userId);
            $claim->update(['status' => ExpenseClaimStatus::PendingApproval, 'rejection_reason' => null]);

            return $claim->fresh('lines.account');
        });
    }

    public function post(ExpenseClaim $claim, int $userId): ExpenseClaim
    {
        if ($claim->status !== ExpenseClaimStatus::Approved) {
            throw FinanceRuleException::invalidTransition($claim->status->value, ExpenseClaimStatus::Posted->value);
        }

        $creditId = $this->resolvePostable((int) $claim->credit_account_id);

        return DB::transaction(function () use ($claim, $userId, $creditId): ExpenseClaim {
            $claim->loadMissing('lines');
            $lines = [];

            foreach ($claim->lines as $line) {
                $lines[] = new PostingLine(
                    accountId: $line->account_id,
                    debit: (float) $line->amount,
                    credit: 0.0,
                    currencyCode: $claim->currency_code,
                    exchangeRate: (float) $claim->exchange_rate,
                    costCenterId: $line->cost_center_id,
                    description: $line->description ?? $claim->description,
                );
            }

            foreach ($this->vatByAccount($claim) as $accountId => $tax) {
                $lines[] = new PostingLine(
                    accountId: $accountId,
                    debit: round($tax, 2),
                    credit: 0.0,
                    currencyCode: $claim->currency_code,
                    exchangeRate: (float) $claim->exchange_rate,
                    costCenterId: null,
                    description: 'Input VAT',
                );
            }

            $lines[] = new PostingLine(
                accountId: $creditId,
                debit: 0.0,
                credit: (float) $claim->total_amount,
                currencyCode: $claim->currency_code,
                exchangeRate: (float) $claim->exchange_rate,
                costCenterId: null,
                description: 'Expense paid — '.$claim->claimant,
            );

            $rows = $this->posting->post($claim, Carbon::parse($claim->expense_date), $lines, $userId);

            $claim->update([
                'status' => ExpenseClaimStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $claim->fresh('lines.account');
        });
    }

    // ----- internals -----

    /**
     * @param array<int, array<string, mixed>> $raw
     * @return list<array<string, mixed>>
     */
    private function normaliseLines(array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('An expense claim needs at least one line.');
        }

        $taxRates = TaxCode::query()->pluck('rate', 'id');
        $lines = [];

        foreach (array_values($raw) as $i => $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            $taxCodeId = isset($line['tax_code_id']) ? (int) $line['tax_code_id'] : null;
            $taxRate = $taxCodeId !== null ? (float) ($taxRates[$taxCodeId] ?? 0) : 0.0;
            $taxAmount = round($amount * $taxRate / 100, 2);

            $lines[] = [
                'line_no' => $i + 1,
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null,
                'description' => $line['description'] ?? null,
                'amount' => $amount,
                'tax_code_id' => $taxCodeId,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => round($amount + $taxAmount, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function totals(array $lines): array
    {
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }

    /**
     * Recoverable input VAT grouped by the postable account it posts to.
     *
     * @return array<int, float>
     */
    private function vatByAccount(ExpenseClaim $claim): array
    {
        $taxAccounts = TaxCode::query()->whereNotNull('input_account_id')->pluck('input_account_id', 'id');
        $out = [];

        foreach ($claim->lines as $line) {
            if ((float) $line->tax_amount <= 0 || $line->tax_code_id === null) {
                continue;
            }
            $inputAccount = $taxAccounts[$line->tax_code_id] ?? null;
            if ($inputAccount === null) {
                continue;
            }
            $resolved = $this->resolvePostable((int) $inputAccount);
            $out[$resolved] = ($out[$resolved] ?? 0) + (float) $line->tax_amount;
        }

        return $out;
    }
}
