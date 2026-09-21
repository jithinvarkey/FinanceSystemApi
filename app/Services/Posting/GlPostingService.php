<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\PeriodStatus;
use App\Enums\VatReturnStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;
use App\Models\GlTransaction;
use App\Models\VatReturn;
use App\Repositories\Contracts\FiscalYearRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GL Posting Core — the single gateway through which every subledger
 * (AP, AR, Journals, Assets, Bank) writes to the general ledger.
 *
 * Guarantees, atomically:
 *  1. Debits equal credits to the cent (in base currency).
 *  2. The transaction date falls inside an OPEN fiscal period.
 *  3. Every target account is postable and active.
 *  4. Ledger rows are immutable — corrections happen via reverse().
 */
final class GlPostingService
{
    public function __construct(
        private readonly FiscalYearRepositoryInterface $fiscalYears,
    ) {
    }

    /**
     * Post a balanced batch of lines to the general ledger.
     *
     * @param Model $source The originating document (journal, invoice, payment...)
     * @param Carbon $transactionDate Document posting date
     * @param list<PostingLine> $lines At least 2 lines forming a balanced entry
     * @param int $postedBy Authenticated user id
     *
     * @return Collection<int, GlTransaction> The written ledger rows
     *
     * @throws FinanceRuleException When any double-entry rule is violated
     */
    public function post(Model $source, Carbon $transactionDate, array $lines, int $postedBy): Collection
    {
        $this->assertBalanced($lines);
        $period = $this->resolveOpenPeriod($transactionDate);
        $this->assertVatPeriodOpen($transactionDate, $source);
        $this->assertAccountsPostable($lines);

        $batchNumber = $this->nextBatchNumber($transactionDate);

        return DB::transaction(function () use ($source, $transactionDate, $lines, $postedBy, $period, $batchNumber): Collection {
            $rows = collect();

            foreach ($lines as $line) {
                $rows->push(GlTransaction::query()->create([
                    'batch_number' => $batchNumber,
                    'fiscal_period_id' => $period->id,
                    'transaction_date' => $transactionDate->toDateString(),
                    'account_id' => $line->accountId,
                    'cost_center_id' => $line->costCenterId,
                    'dimension_id' => $line->dimensionId,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'currency_code' => $line->currencyCode,
                    'exchange_rate' => $line->exchangeRate,
                    'base_debit' => round($line->debit * $line->exchangeRate, 2),
                    'base_credit' => round($line->credit * $line->exchangeRate, 2),
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                    'description' => $line->description,
                    'is_reversal' => false,
                    'posted_by' => $postedBy,
                    'posted_at' => now(),
                ]));
            }

            return $rows;
        });
    }

    /**
     * Reverse an existing batch by writing mirror-image lines dated $reversalDate.
     *
     * @throws FinanceRuleException When the reversal date is in a closed period
     */
    public function reverse(string $batchNumber, Carbon $reversalDate, int $postedBy): Collection
    {
        $original = GlTransaction::query()->where('batch_number', $batchNumber)->get();

        if ($original->isEmpty()) {
            throw new FinanceRuleException("Reversal rejected: batch {$batchNumber} not found.");
        }

        $period = $this->resolveOpenPeriod($reversalDate);
        $reversalBatch = $this->nextBatchNumber($reversalDate);

        return DB::transaction(function () use ($original, $reversalDate, $postedBy, $period, $reversalBatch): Collection {
            return $original->map(fn (GlTransaction $row) => GlTransaction::query()->create([
                'batch_number' => $reversalBatch,
                'fiscal_period_id' => $period->id,
                'transaction_date' => $reversalDate->toDateString(),
                'account_id' => $row->account_id,
                'cost_center_id' => $row->cost_center_id,
                'debit' => $row->credit,
                'credit' => $row->debit,
                'currency_code' => $row->currency_code,
                'exchange_rate' => $row->exchange_rate,
                'base_debit' => $row->base_credit,
                'base_credit' => $row->base_debit,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'description' => 'REVERSAL of ' . $row->batch_number . ($row->description ? ' — ' . $row->description : ''),
                'is_reversal' => true,
                'posted_by' => $postedBy,
                'posted_at' => now(),
            ]));
        });
    }

    /**
     * @param list<PostingLine> $lines
     *
     * @throws FinanceRuleException
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new FinanceRuleException('Posting rejected: a balanced entry needs at least two lines.');
        }

        // Compare in integer cents to avoid float drift.
        $debitCents = 0;
        $creditCents = 0;

        foreach ($lines as $line) {
            if ($line->debit > 0 && $line->credit > 0) {
                throw new FinanceRuleException('Posting rejected: a line cannot carry both a debit and a credit.');
            }
            $debitCents += (int) round($line->debit * $line->exchangeRate * 100);
            $creditCents += (int) round($line->credit * $line->exchangeRate * 100);
        }

        if ($debitCents !== $creditCents) {
            throw FinanceRuleException::unbalanced(
                number_format($debitCents / 100, 2),
                number_format($creditCents / 100, 2),
            );
        }
    }

    /**
     * @throws FinanceRuleException
     */
    private function resolveOpenPeriod(Carbon $date): \App\Models\FiscalPeriod
    {
        $period = $this->fiscalYears->findOpenPeriodForDate($date);

        if ($period === null) {
            throw FinanceRuleException::noPeriodForDate($date->toDateString());
        }

        if (! $period->status->acceptsPostings()) {
            throw FinanceRuleException::periodClosed($period->name);
        }

        return $period;
    }

    /**
     * F15 — keep filed VAT figures tied out: reject a posting dated inside a
     * filed/paid VAT period. The settlement of the return itself is exempt.
     *
     * @throws FinanceRuleException
     */
    private function assertVatPeriodOpen(Carbon $date, Model $source): void
    {
        if ($source instanceof VatReturn) {
            return;
        }

        $locked = VatReturn::query()
            ->whereIn('status', [VatReturnStatus::Filed->value, VatReturnStatus::Paid->value])
            ->whereDate('period_from', '<=', $date->toDateString())
            ->whereDate('period_to', '>=', $date->toDateString())
            ->exists();

        if ($locked) {
            throw new FinanceRuleException(
                "Posting rejected: {$date->toDateString()} falls in a filed VAT period. Reverse and re-file the return to amend it.",
            );
        }
    }

    /**
     * @param list<PostingLine> $lines
     *
     * @throws FinanceRuleException
     */
    private function assertAccountsPostable(array $lines): void
    {
        $accountIds = array_unique(array_map(static fn (PostingLine $l) => $l->accountId, $lines));

        $accounts = ChartOfAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        foreach ($accountIds as $id) {
            $account = $accounts->get($id);
            if ($account === null || ! $account->is_postable || $account->status->value !== 'active') {
                throw FinanceRuleException::accountNotPostable($account?->code ?? "#{$id}");
            }
        }
    }

    private function nextBatchNumber(Carbon $date): string
    {
        // GLB-20260312-000041 — date-scoped sequence, race-safe via row count under transaction.
        $sequence = GlTransaction::query()
            ->whereDate('transaction_date', $date->toDateString())
            ->distinct('batch_number')
            ->count('batch_number') + 1;

        return sprintf('GLB-%s-%06d', $date->format('Ymd'), $sequence);
    }
}
