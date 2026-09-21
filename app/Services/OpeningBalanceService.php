<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\JournalEntry;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P2.9 — Opening balances.
 *
 * Loads a cutover trial balance as a single, directly-posted journal at the
 * cutover date (bypassing the maker-checker workflow — this is a privileged
 * setup action gated on general-ledger.post). Any net imbalance is plugged to
 * a chosen equity account (typically "Opening Balance Equity"), so the operator
 * can enter just the asset/liability/real balances and let the plug square it.
 *
 * Posts through the single GL gateway, so balance / open-period / postable
 * guarantees all apply. This is the prerequisite for the historical import
 * (see docs/finance-design/insurance-broker-transaction-flow.md §16.2).
 */
final class OpeningBalanceService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines  each: account_id, debit, credit
     *
     * @throws FinanceRuleException
     */
    public function post(Carbon $cutover, array $rawLines, int $userId, ?int $equityAccountId = null, ?string $description = null): JournalEntry
    {
        $lines = $this->normaliseLines($rawLines);
        $lines = $this->balanceWithEquity($lines, $equityAccountId);

        if (count($lines) < 2) {
            throw new FinanceRuleException('Opening balances need at least two lines (and must balance).');
        }

        return DB::transaction(function () use ($cutover, $lines, $userId, $description): JournalEntry {
            $journal = JournalEntry::query()->create([
                'journal_number' => $this->numbers->next('opening_balance', $cutover),
                'journal_date' => $cutover->toDateString(),
                'reference' => 'OPENING',
                'description' => $description ?? ('Opening balances as of '.$cutover->toDateString()),
                'status' => JournalStatus::Posted,
                'is_opening' => true,
                'currency_code' => 'SAR',
                'total_debit' => round(array_sum(array_column($lines, 'debit')), 2),
                'total_credit' => round(array_sum(array_column($lines, 'credit')), 2),
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal->lines()->createMany($lines);

            $postingLines = array_map(
                fn (array $l): PostingLine => new PostingLine(
                    accountId: (int) $l['account_id'],
                    debit: (float) $l['debit'],
                    credit: (float) $l['credit'],
                    currencyCode: 'SAR',
                    exchangeRate: 1.0,
                    costCenterId: null,
                    description: 'Opening balance',
                ),
                $lines,
            );

            $rows = $this->posting->post($journal, $cutover, $postingLines, $userId);
            $journal->update(['fiscal_period_id' => $rows->first()->fiscal_period_id]);

            return $journal->load('lines.account');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     *
     * @throws FinanceRuleException
     */
    private function normaliseLines(array $raw): array
    {
        if (count($raw) < 1) {
            throw new FinanceRuleException('Enter at least one opening-balance line.');
        }

        $lines = [];
        foreach (array_values($raw) as $i => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit > 0 && $credit > 0) {
                throw FinanceRuleException::mixedLine($i + 1);
            }
            if ($debit <= 0 && $credit <= 0) {
                continue; // skip empty rows
            }

            $lines[] = [
                'line_no' => count($lines) + 1,
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => null,
                'description' => $line['description'] ?? 'Opening balance',
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => 'SAR',
                'exchange_rate' => 1,
            ];
        }

        return $lines;
    }

    /**
     * Plug any net imbalance to the equity account. If already balanced, the
     * equity account is optional.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws FinanceRuleException
     */
    private function balanceWithEquity(array $lines, ?int $equityAccountId): array
    {
        $netCents = 0;
        foreach ($lines as $l) {
            $netCents += (int) round(((float) $l['debit'] - (float) $l['credit']) * 100);
        }

        if ($netCents === 0) {
            return $lines;
        }

        if ($equityAccountId === null) {
            throw FinanceRuleException::unbalanced(
                number_format(array_sum(array_column($lines, 'debit')), 2, '.', ''),
                number_format(array_sum(array_column($lines, 'credit')), 2, '.', ''),
            );
        }

        $plug = round(abs($netCents) / 100, 2);
        $lines[] = [
            'line_no' => count($lines) + 1,
            'account_id' => $equityAccountId,
            'cost_center_id' => null,
            'description' => 'Opening balance equity (plug)',
            // net debit > 0 → credit the equity to balance; net credit > 0 → debit it.
            'debit' => $netCents < 0 ? $plug : 0.0,
            'credit' => $netCents > 0 ? $plug : 0.0,
            'currency_code' => 'SAR',
            'exchange_rate' => 1,
        ];

        return $lines;
    }
}
