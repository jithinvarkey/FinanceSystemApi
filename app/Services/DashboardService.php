<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Policy;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the live work-queue counts that drive the Accountant dashboard
 * (the productivity cockpit). Reads only from modules already built; future
 * AR / banking / tax tiles light up as those modules ship.
 */
final class DashboardService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AccountsReceivableReportService $arReports,
    ) {
    }

    /**
     * E1 — Executive Command Center: forward-looking and analytical widgets that
     * sit alongside the headline KPIs. All computed live from posted data.
     *
     * @return array<string, mixed>
     */
    public function commandCenter(?int $year = null): array
    {
        $today = Carbon::today();
        $cash = $this->cashPosition();

        // Year-scoped widgets (insurer/product premium, budget-vs-actual) use the
        // selected fiscal year; forecast/aging/top-customers stay current snapshots.
        $year ??= (int) now()->format('Y');
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();

        // 90-day cash-flow forecast in three monthly buckets. The first bucket
        // sweeps in everything due up to this month-end (incl. overdue).
        $forecast = [];
        $running = $cash;
        for ($i = 0; $i < 3; $i++) {
            $monthEnd = $today->copy()->addMonths($i)->endOfMonth();
            $from = $i === 0 ? null : $today->copy()->addMonths($i)->startOfMonth();

            $in = $this->dueBalance('customer_invoices', $from, $monthEnd);
            $out = $this->dueBalance('vendor_invoices', $from, $monthEnd);
            $running = round($running + $in - $out, 2);

            $forecast[] = [
                'month' => $monthEnd->format('M Y'),
                'expected_in' => $in,
                'expected_out' => $out,
                'net' => round($in - $out, 2),
                'projected_cash' => $running,
            ];
        }

        // Top customers by outstanding receivable.
        $topCustomers = DB::table('customer_invoices as ci')
            ->join('customers as c', 'c.id', '=', 'ci.customer_id')
            ->where('ci.status', 'posted')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.name, SUM(ci.total_amount - ci.amount_paid) outstanding')
            ->havingRaw('SUM(ci.total_amount - ci.amount_paid) > 0.01')
            ->orderByDesc('outstanding')->limit(5)->get()
            ->map(fn ($r): array => ['name' => $r->name, 'outstanding' => round((float) $r->outstanding, 2)]);

        // Top insurers by gross written premium (insurer = vendor) for the year.
        $topInsurers = DB::table('policies as p')
            ->join('vendors as v', 'v.id', '=', 'p.insurer_id')
            ->where('p.status', 'issued')
            ->whereBetween('p.start_date', [$yearStart, $yearEnd])
            ->groupBy('v.id', 'v.name')
            ->selectRaw('v.name, SUM(p.gross_premium) gwp, SUM(p.commission_amount) commission')
            ->orderByDesc('gwp')->limit(5)->get()
            ->map(fn ($r): array => ['name' => $r->name, 'gwp' => round((float) $r->gwp, 2), 'commission' => round((float) $r->commission, 2)]);

        // Profitability by product (broker commission revenue) for the year.
        $byProduct = DB::table('policies as p')
            ->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.status', 'issued')
            ->whereBetween('p.start_date', [$yearStart, $yearEnd])
            ->groupBy('pr.id', 'pr.name')
            ->selectRaw('pr.name, COUNT(*) policies, SUM(p.gross_premium) gwp, SUM(p.commission_amount) commission')
            ->orderByDesc('commission')->limit(6)->get()
            ->map(fn ($r): array => ['name' => $r->name, 'policies' => (int) $r->policies, 'gwp' => round((float) $r->gwp, 2), 'commission' => round((float) $r->commission, 2)]);

        // Aging + collection-risk index (0 = healthy, 100 = all severely overdue).
        $aging = $this->arReports->aging($today);
        $t = $aging['totals'];
        $arTotal = (float) ($t['total'] ?? 0);
        $weighted = (float) ($t['1_30'] ?? 0) * 0.2 + (float) ($t['31_60'] ?? 0) * 0.4
            + (float) ($t['61_90'] ?? 0) * 0.6 + (float) ($t['91_120'] ?? 0) * 0.8 + (float) ($t['120_plus'] ?? 0) * 1.0;
        $riskIndex = $arTotal > 0 ? round($weighted / $arTotal * 100, 1) : 0.0;

        return [
            'currency' => 'SAR',
            'cash_forecast' => $forecast,
            'top_customers' => $topCustomers->all(),
            'top_insurers' => $topInsurers->all(),
            'profitability_by_product' => $byProduct->all(),
            'collection_risk_index' => $riskIndex,
            'aging' => [
                'current' => round((float) ($t['current'] ?? 0), 2),
                '1_30' => round((float) ($t['1_30'] ?? 0), 2),
                '31_60' => round((float) ($t['31_60'] ?? 0), 2),
                '61_90' => round((float) ($t['61_90'] ?? 0), 2),
                '91_120' => round((float) ($t['91_120'] ?? 0), 2),
                '120_plus' => round((float) ($t['120_plus'] ?? 0), 2),
                'total' => round($arTotal, 2),
            ],
            'budget_vs_actual' => $this->budgetVsActual($year),
            'year' => $year,
        ];
    }

    /** Outstanding balance of posted invoices due within a window. */
    private function dueBalance(string $table, ?Carbon $from, Carbon $to): float
    {
        return round((float) (DB::table($table)
            ->where('status', 'posted')
            ->when($from !== null, fn ($q) => $q->whereDate('due_date', '>=', $from->toDateString()))
            ->whereDate('due_date', '<=', $to->toDateString())
            ->selectRaw('SUM(total_amount - amount_paid) bal')
            ->value('bal') ?? 0), 2);
    }

    private function cashPosition(): float
    {
        return round((float) (DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('a.is_bank_account', true)
            ->selectRaw('SUM(g.base_debit - g.base_credit) bal')
            ->value('bal') ?? 0), 2);
    }

    /**
     * Budget vs actual expense for the current fiscal year.
     *
     * @return array{budget: float, actual: float, variance: float, used_pct: float}
     */
    private function budgetVsActual(?int $forYear = null): array
    {
        $y = $forYear ?? (int) now()->format('Y');
        // The fiscal year whose range overlaps the calendar year (calendar-aligned here).
        $year = DB::table('fiscal_years')
            ->whereDate('start_date', '<=', Carbon::create($y, 12, 31)->toDateString())
            ->whereDate('end_date', '>=', Carbon::create($y, 1, 1)->toDateString())
            ->orderByDesc('start_date')->first();
        if ($year === null) {
            return ['budget' => 0.0, 'actual' => 0.0, 'variance' => 0.0, 'used_pct' => 0.0];
        }

        $budget = (float) (DB::table('budget_lines as bl')
            ->join('budgets as b', 'b.id', '=', 'bl.budget_id')
            ->where('b.fiscal_year_id', $year->id)
            ->sum('bl.annual_amount'));

        $actual = (float) (DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('a.account_type', 'expense')
            ->whereDate('g.transaction_date', '>=', $year->start_date)
            ->whereDate('g.transaction_date', '<=', $year->end_date)
            ->selectRaw('SUM(g.base_debit - g.base_credit) v')->value('v') ?? 0);

        return [
            'budget' => round($budget, 2),
            'actual' => round($actual, 2),
            'variance' => round($budget - $actual, 2),
            'used_pct' => $budget > 0 ? round($actual / $budget * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountantSummary(User $user): array
    {
        $journals = JournalEntry::query()
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $vendors = Vendor::query()
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $unposted = (int) ($journals['draft'] ?? 0)
            + (int) ($journals['submitted'] ?? 0)
            + (int) ($journals['approved'] ?? 0);

        return [
            'my_pending_approvals' => $this->approvals->pendingFor($user)->count(),
            'journals' => [
                'draft' => (int) ($journals['draft'] ?? 0),
                'submitted' => (int) ($journals['submitted'] ?? 0),
                'approved' => (int) ($journals['approved'] ?? 0),  // ready to post
                'posted' => (int) ($journals['posted'] ?? 0),
                'unposted' => $unposted,
            ],
            'vendors' => [
                'draft' => (int) ($vendors['draft'] ?? 0),
                'pending_approval' => (int) ($vendors['pending_approval'] ?? 0),
                'active' => (int) ($vendors['active'] ?? 0),
                'blocked' => Vendor::query()->where('is_blocked', true)->count(),
            ],
        ];
    }

    /**
     * Executive KPIs + 12-month trend, computed live from the general ledger
     * (base currency). Everything is genuinely data-driven — it reads zero
     * until transactions are posted, then fills in.
     *
     * @return array<string, mixed>
     */
    public function executiveSummary(User $user, ?int $year = null): array
    {
        // Flow KPIs (revenue/expense/GWP/commission/trend) are scoped to a fiscal
        // year so they stay meaningful once multiple years of history exist.
        // Balance KPIs (cash/AR/AP/VAT) are always "as of now" snapshots.
        $year ??= (int) now()->format('Y');
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();

        // Net balance per account type (signed: debit − credit in base currency) for the year.
        $byType = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->whereBetween('g.transaction_date', [$yearStart, $yearEnd])
            ->selectRaw('a.account_type, SUM(g.base_debit) d, SUM(g.base_credit) c')
            ->groupBy('a.account_type')
            ->get()
            ->keyBy('account_type');

        $debitMinusCredit = fn (?object $r) => $r ? (float) $r->d - (float) $r->c : 0.0;

        $revenue = -$debitMinusCredit($byType->get('revenue'));     // credit-positive
        $expenses = $debitMinusCredit($byType->get('expense'));     // debit-positive
        $netProfit = $revenue - $expenses;

        $cash = (float) (DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('a.is_bank_account', true)
            ->selectRaw('SUM(g.base_debit - g.base_credit) bal')
            ->value('bal') ?? 0);

        $postedCount = (int) DB::table('gl_transactions')->count();

        return [
            'currency' => 'SAR',
            'kpis' => [
                'revenue' => round($revenue, 2),
                'expenses' => round($expenses, 2),
                'net_profit' => round($netProfit, 2),
                'profit_margin' => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0,
                'cash' => round($cash, 2),
                'receivables' => round($this->prefixBalance('1205'), 2),       // AR control subtree
                'payables' => round(-$this->prefixBalance('221'), 2),          // AP — present positive
                'vat_payable' => round(-$this->prefixBalance('223'), 2),       // Output VAT
                // Broker KPIs (P4.17) — from policies issued in the year.
                'gwp' => round((float) Policy::query()->where('status', 'issued')->whereBetween('start_date', [$yearStart, $yearEnd])->sum('gross_premium'), 2),
                'commission' => round((float) Policy::query()->where('status', 'issued')->whereBetween('start_date', [$yearStart, $yearEnd])->sum('commission_amount'), 2),
            ],
            'year' => $year,
            'trend' => $this->monthlyTrend($year),
            'queues' => [
                'my_approvals' => $this->approvals->pendingFor($user)->count(),
                'journals_unposted' => JournalEntry::query()->whereIn('status', ['draft', 'submitted', 'approved'])->count(),
                'journals_ready' => JournalEntry::query()->where('status', 'approved')->count(),
                'vendors_pending' => Vendor::query()->where('status', 'pending_approval')->count(),
                'vendors_blocked' => Vendor::query()->where('is_blocked', true)->count(),
            ],
            'posted_count' => $postedCount,
        ];
    }

    /** Signed base-currency balance (debit − credit) of an account-code subtree. */
    private function prefixBalance(string $codePrefix): float
    {
        $row = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('a.code', 'like', $codePrefix.'%')
            ->selectRaw('SUM(g.base_debit) d, SUM(g.base_credit) c')
            ->first();

        return $row ? (float) $row->d - (float) $row->c : 0.0;
    }

    /**
     * Last 12 months of revenue and expense (base currency), bucketed in PHP
     * so it works on any database driver.
     *
     * @return list<array{month: string, revenue: float, expenses: float}>
     */
    private function monthlyTrend(?int $year = null): array
    {
        // Jan–Dec of the selected fiscal year (defaults to the current year).
        $start = Carbon::create($year ?? (int) now()->format('Y'), 1, 1)->startOfMonth();

        $rows = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->whereIn('a.account_type', ['revenue', 'expense'])
            ->whereBetween('g.transaction_date', [$start->toDateString(), $start->copy()->endOfYear()->toDateString()])
            ->selectRaw('g.transaction_date td, a.account_type atype, g.base_debit d, g.base_credit c')
            ->get();

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $key = $start->copy()->addMonths($i);
            $buckets[$key->format('Y-m')] = ['month' => $key->format('M'), 'revenue' => 0.0, 'expenses' => 0.0];
        }

        foreach ($rows as $r) {
            $key = Carbon::parse($r->td)->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            if ($r->atype === 'revenue') {
                $buckets[$key]['revenue'] += (float) $r->c - (float) $r->d;
            } else {
                $buckets[$key]['expenses'] += (float) $r->d - (float) $r->c;
            }
        }

        return array_map(static fn (array $b) => [
            'month' => $b['month'],
            'revenue' => round($b['revenue'], 2),
            'expenses' => round($b['expenses'], 2),
        ], array_values($buckets));
    }
}
