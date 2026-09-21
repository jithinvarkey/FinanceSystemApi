<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\JournalEntry;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P10 — Year-end close. Posts the closing journal that zeroes every P&L account
 * (Dr revenue / Cr expense) and rolls the net result to retained earnings, then
 * locks the year's periods. After this the balance sheet carries the result in
 * equity and the new year starts with clean P&L accounts.
 */
final class YearEndCloseService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /**
     * Net result + the P&L balances that will be closed.
     *
     * @return array{fiscal_year: string, revenue: string, expenses: string, net_profit: string, accounts: int}
     */
    public function preview(FiscalYear $year): array
    {
        $balances = $this->plBalances($year);
        $revenue = array_sum(array_column(array_filter($balances, fn ($b) => $b['kind'] === 'revenue'), 'balance'));
        $expense = array_sum(array_column(array_filter($balances, fn ($b) => $b['kind'] === 'expense'), 'balance'));

        return [
            'fiscal_year' => $year->code,
            'revenue' => number_format($revenue, 2, '.', ''),
            'expenses' => number_format($expense, 2, '.', ''),
            'net_profit' => number_format(round($revenue - $expense, 2), 2, '.', ''),
            'accounts' => count($balances),
        ];
    }

    /** Post the closing journal and lock the year. */
    public function close(FiscalYear $year, int $retainedEarningsAccountId, int $userId): JournalEntry
    {
        if ($year->status === 'closed') {
            throw new FinanceRuleException("Fiscal year {$year->code} is already closed.");
        }

        $balances = $this->plBalances($year);
        if (count($balances) === 0) {
            throw new FinanceRuleException('There is nothing to close — no profit & loss activity in this year.');
        }

        $retainedId = $this->resolvePostable($retainedEarningsAccountId);
        $closeDate = Carbon::parse((string) $year->end_date);

        return DB::transaction(function () use ($year, $balances, $retainedId, $closeDate, $userId): JournalEntry {
            $lines = [];
            $revenue = 0.0;
            $expense = 0.0;

            foreach ($balances as $b) {
                if ($b['kind'] === 'revenue') {
                    $lines[] = new PostingLine($b['account_id'], round($b['balance'], 2), 0.0, 'SAR', 1.0, null, 'Year-end close: revenue');
                    $revenue += $b['balance'];
                } else {
                    $lines[] = new PostingLine($b['account_id'], 0.0, round($b['balance'], 2), 'SAR', 1.0, null, 'Year-end close: expense');
                    $expense += $b['balance'];
                }
            }

            $net = round($revenue - $expense, 2);
            if ($net > 0) {
                $lines[] = new PostingLine($retainedId, 0.0, $net, 'SAR', 1.0, null, 'Net profit to retained earnings');
            } elseif ($net < 0) {
                $lines[] = new PostingLine($retainedId, abs($net), 0.0, 'SAR', 1.0, null, 'Net loss to retained earnings');
            }

            $journal = JournalEntry::query()->create([
                'journal_number' => $this->numbers->next('journal_entry', $closeDate),
                'journal_date' => $closeDate->toDateString(),
                'reference' => 'YEAR-END',
                'description' => "Year-end closing — {$year->code}",
                'status' => JournalStatus::Posted,
                'currency_code' => 'SAR',
                'total_debit' => round(array_sum(array_map(fn (PostingLine $l) => $l->debit, $lines)), 2),
                'total_credit' => round(array_sum(array_map(fn (PostingLine $l) => $l->credit, $lines)), 2),
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $rows = $this->posting->post($journal, $closeDate, $lines, $userId);
            $journal->update(['fiscal_period_id' => $rows->first()->fiscal_period_id]);

            // Lock the year and its periods.
            FiscalPeriod::query()->where('fiscal_year_id', $year->id)
                ->update(['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now()]);
            $year->update(['status' => 'closed']);

            return $journal->load('lines.account');
        });
    }

    /**
     * Net movement per P&L account for the year (positive in the account's
     * natural direction).
     *
     * @return list<array{account_id: int, kind: string, balance: float}>
     */
    private function plBalances(FiscalYear $year): array
    {
        $accounts = ChartOfAccount::query()
            ->whereIn('account_type', ['revenue', 'expense'])
            ->where('is_postable', true)
            ->pluck('account_type', 'id');

        $out = [];
        foreach ($accounts as $id => $type) {
            $sums = GlTransaction::query()
                ->where('account_id', $id)
                ->whereBetween('transaction_date', [(string) $year->start_date, (string) $year->end_date])
                ->selectRaw('COALESCE(SUM(base_debit),0) d, COALESCE(SUM(base_credit),0) c')
                ->first();

            $kind = ($type instanceof \BackedEnum ? $type->value : (string) $type);
            $balance = $kind === 'revenue'
                ? round((float) $sums->c - (float) $sums->d, 2)
                : round((float) $sums->d - (float) $sums->c, 2);

            if (abs($balance) >= 0.01) {
                $out[] = ['account_id' => (int) $id, 'kind' => $kind, 'balance' => $balance];
            }
        }

        return $out;
    }
}
