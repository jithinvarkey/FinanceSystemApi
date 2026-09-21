<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * P11 — Core financial statements built straight from the GL: Trial Balance,
 * Income Statement (P&L) and Balance Sheet. Read-only; everything reconciles
 * because every GL batch is balanced (debit = credit).
 *
 * Sign convention per account type:
 *   asset / expense  → debit-positive  (Σdebit − Σcredit)
 *   liability/equity/revenue → credit-positive (Σcredit − Σdebit)
 */
final class FinancialStatementService
{
    /**
     * Per-account GL balances (with account meta), filtered by date window and
     * optionally account type. Posted GL only — the GL holds posted rows.
     *
     * @param  list<string>  $types
     * @return Collection<int, object>
     */
    private function accountBalances(?Carbon $from, ?Carbon $to, array $types = []): Collection
    {
        return DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('g.transaction_date', '<=', $to->toDateString()))
            ->when($types !== [], fn ($q) => $q->whereIn('a.account_type', $types))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type')
            ->selectRaw('a.code, a.name, a.account_type, SUM(g.base_debit) d, SUM(g.base_credit) c')
            ->orderBy('a.code')
            ->get();
    }

    /**
     * Trial balance as of a date — every account with a balance, on its side.
     *
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array{debit: float, credit: float}, balanced: bool}
     */
    public function trialBalance(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::today();
        $rows = [];
        $totalDebit = $totalCredit = 0.0;

        foreach ($this->accountBalances(null, $asOf) as $r) {
            $net = round((float) $r->d - (float) $r->c, 2);
            if ($net === 0.0) {
                continue;
            }
            $debit = $net > 0 ? $net : 0.0;
            $credit = $net < 0 ? round(-$net, 2) : 0.0;

            $rows[] = ['code' => $r->code, 'name' => $r->name, 'account_type' => $r->account_type, 'debit' => $debit, 'credit' => $credit];
            $totalDebit = round($totalDebit + $debit, 2);
            $totalCredit = round($totalCredit + $credit, 2);
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'balanced' => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * Income statement (P&L) for a date range: revenue less expenses.
     *
     * @return array{from: ?string, to: string, revenue: list<array<string,mixed>>, revenue_total: float, expenses: list<array<string,mixed>>, expenses_total: float, net_profit: float}
     */
    public function incomeStatement(?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::today();
        $revenue = $expenses = [];
        $revenueTotal = $expensesTotal = 0.0;

        foreach ($this->accountBalances($from, $to, ['revenue', 'expense']) as $r) {
            if ($r->account_type === 'revenue') {
                $amount = round((float) $r->c - (float) $r->d, 2);
                if ($amount === 0.0) {
                    continue;
                }
                $revenue[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $revenueTotal = round($revenueTotal + $amount, 2);
            } else {
                $amount = round((float) $r->d - (float) $r->c, 2);
                if ($amount === 0.0) {
                    continue;
                }
                $expenses[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $expensesTotal = round($expensesTotal + $amount, 2);
            }
        }

        return [
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => $revenue,
            'revenue_total' => $revenueTotal,
            'expenses' => $expenses,
            'expenses_total' => $expensesTotal,
            'net_profit' => round($revenueTotal - $expensesTotal, 2),
        ];
    }

    /**
     * Balance sheet as of a date. Profit-and-loss isn't closed to equity yet
     * (no year-end close), so current-period earnings are shown within equity.
     *
     * @return array{as_of: string, assets: list<array<string,mixed>>, assets_total: float, liabilities: list<array<string,mixed>>, liabilities_total: float, equity: list<array<string,mixed>>, current_earnings: float, equity_total: float, liabilities_equity_total: float, balanced: bool}
     */
    public function balanceSheet(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::today();
        $assets = $liabilities = $equity = [];
        $assetsTotal = $liabilitiesTotal = $equityTotal = 0.0;

        foreach ($this->accountBalances(null, $asOf, ['asset', 'liability', 'equity']) as $r) {
            if ($r->account_type === 'asset') {
                $amount = round((float) $r->d - (float) $r->c, 2);
                if ($amount === 0.0) {
                    continue;
                }
                $assets[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $assetsTotal = round($assetsTotal + $amount, 2);
            } elseif ($r->account_type === 'liability') {
                $amount = round((float) $r->c - (float) $r->d, 2);
                if ($amount === 0.0) {
                    continue;
                }
                $liabilities[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $liabilitiesTotal = round($liabilitiesTotal + $amount, 2);
            } else {
                $amount = round((float) $r->c - (float) $r->d, 2);
                if ($amount === 0.0) {
                    continue;
                }
                $equity[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $equityTotal = round($equityTotal + $amount, 2);
            }
        }

        // Current-period earnings (revenue − expense to date) sit in equity until closed.
        $earnings = $this->incomeStatement(null, $asOf)['net_profit'];
        $equityTotal = round($equityTotal + $earnings, 2);

        return [
            'as_of' => $asOf->toDateString(),
            'assets' => $assets,
            'assets_total' => $assetsTotal,
            'liabilities' => $liabilities,
            'liabilities_total' => $liabilitiesTotal,
            'equity' => $equity,
            'current_earnings' => $earnings,
            'equity_total' => $equityTotal,
            'liabilities_equity_total' => round($liabilitiesTotal + $equityTotal, 2),
            'balanced' => abs($assetsTotal - ($liabilitiesTotal + $equityTotal)) < 0.01,
        ];
    }

    /**
     * Cash-flow statement for a period (direct method, GL-derived). Explains the
     * movement of cash & bank accounts by classifying every NON-cash account's
     * movement into Operating / Investing (fixed-asset accounts) / Financing
     * (equity). Always reconciles because the GL is balanced.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(?Carbon $from, ?Carbon $to): array
    {
        $to = $to ?? Carbon::today();

        $cashIds = DB::table('chart_of_accounts')->where('is_bank_account', true)->pluck('id')->all();
        $fixedIds = DB::table('fixed_assets')->pluck('asset_account_id')
            ->merge(DB::table('fixed_assets')->pluck('accum_depreciation_account_id'))->unique()->all();

        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        $totals = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];

        foreach ($this->accountBalances($from, $to) as $r) {
            $accId = DB::table('chart_of_accounts')->where('code', $r->code)->value('id');
            if (in_array($accId, $cashIds, true)) {
                continue; // cash itself — the thing being explained
            }
            // Cash impact = credit − debit (an asset increase via debit consumes cash).
            $contribution = round((float) $r->c - (float) $r->d, 2);
            if ($contribution === 0.0) {
                continue;
            }

            $bucket = in_array($accId, $fixedIds, true) ? 'investing'
                : ($r->account_type === 'equity' ? 'financing' : 'operating');

            $sections[$bucket][] = ['code' => $r->code, 'name' => $r->name, 'amount' => $contribution];
            $totals[$bucket] = round($totals[$bucket] + $contribution, 2);
        }

        $netChange = round($totals['operating'] + $totals['investing'] + $totals['financing'], 2);

        $openingCash = $from !== null ? $this->cashBalance($cashIds, $from->copy()->subDay()) : 0.0;
        $closingCash = $this->cashBalance($cashIds, $to);

        return [
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'operating' => $sections['operating'],
            'operating_total' => $totals['operating'],
            'investing' => $sections['investing'],
            'investing_total' => $totals['investing'],
            'financing' => $sections['financing'],
            'financing_total' => $totals['financing'],
            'net_change' => $netChange,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'reconciles' => abs(round($openingCash + $netChange - $closingCash, 2)) < 0.01,
        ];
    }

    /** Net cash & bank balance (debit-positive) up to and including $asOf. */
    private function cashBalance(array $cashIds, ?Carbon $asOf): float
    {
        if ($cashIds === []) {
            return 0.0;
        }
        $q = DB::table('gl_transactions')->whereIn('account_id', $cashIds);
        if ($asOf !== null) {
            $q->whereDate('transaction_date', '<=', $asOf->toDateString());
        }
        $s = $q->selectRaw('COALESCE(SUM(base_debit),0) d, COALESCE(SUM(base_credit),0) c')->first();

        return round((float) $s->d - (float) $s->c, 2);
    }

    /**
     * Account ledger (drill-down): every posted GL line on one account, with an
     * opening balance carried in from before `from` and a running balance.
     *
     * @return array{account: array<string,mixed>, opening: float, rows: list<array<string,mixed>>, closing: float, totals: array{debit: float, credit: float}}
     */
    public function accountLedger(int $accountId, ?Carbon $from, ?Carbon $to): array
    {
        $account = DB::table('chart_of_accounts')->where('id', $accountId)->first();
        if ($account === null) {
            throw new \App\Exceptions\FinanceRuleException('Account not found.');
        }
        $debitPositive = in_array($account->account_type, ['asset', 'expense'], true);

        // Opening = net movement strictly before the window start (0 if no start).
        $opening = 0.0;
        if ($from !== null) {
            $o = DB::table('gl_transactions')->where('account_id', $accountId)
                ->whereDate('transaction_date', '<', $from->toDateString())
                ->selectRaw('COALESCE(SUM(base_debit),0) d, COALESCE(SUM(base_credit),0) c')->first();
            $opening = round($debitPositive ? (float) $o->d - (float) $o->c : (float) $o->c - (float) $o->d, 2);
        }

        $txns = DB::table('gl_transactions as g')
            ->where('g.account_id', $accountId)
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('g.transaction_date', '<=', $to->toDateString()))
            ->orderBy('g.transaction_date')->orderBy('g.id')
            ->get(['g.transaction_date', 'g.batch_number', 'g.description', 'g.base_debit', 'g.base_credit', 'g.source_type', 'g.source_id']);

        $running = $opening;
        $totalDebit = $totalCredit = 0.0;
        $rows = [];
        foreach ($txns as $t) {
            $running = round($running + ($debitPositive ? (float) $t->base_debit - (float) $t->base_credit : (float) $t->base_credit - (float) $t->base_debit), 2);
            $rows[] = [
                'date' => $t->transaction_date,
                'batch_number' => $t->batch_number,
                'source' => $t->source_type ? class_basename($t->source_type).' #'.$t->source_id : null,
                'description' => $t->description,
                'debit' => (float) $t->base_debit,
                'credit' => (float) $t->base_credit,
                'balance' => $running,
            ];
            $totalDebit = round($totalDebit + (float) $t->base_debit, 2);
            $totalCredit = round($totalCredit + (float) $t->base_credit, 2);
        }

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type],
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
        ];
    }
}
