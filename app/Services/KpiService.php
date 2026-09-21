<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KpiTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * N3 — KPI engine. Computes each catalogued KPI's value from the GL and
 * sub-ledgers, compares it to its target, and assembles role scorecards.
 */
final class KpiService
{
    public function __construct(private readonly AccountsReceivableReportService $ar)
    {
    }

    /**
     * The full KPI library with computed values, targets and status.
     *
     * @return list<array<string, mixed>>
     */
    public function library(): array
    {
        $values = $this->computeValues();

        return KpiTarget::query()->orderBy('display_order')->get()->map(function (KpiTarget $k) use ($values): array {
            $value = round((float) ($values[$k->kpi_key] ?? 0), 2);
            $target = $k->target !== null ? (float) $k->target : null;

            return [
                'kpi_key' => $k->kpi_key, 'name' => $k->name, 'category' => $k->category,
                'unit' => $k->unit, 'direction' => $k->direction, 'audiences' => array_filter(explode(',', $k->audiences)),
                'value' => $value, 'target' => $target, 'status' => $this->status($value, $target, $k->direction),
            ];
        })->all();
    }

    /**
     * A role scorecard: the KPIs flagged for that audience.
     *
     * @return array{audience: string, kpis: list<array<string, mixed>>}
     */
    public function scorecard(string $audience): array
    {
        $kpis = array_values(array_filter($this->library(), fn (array $k): bool => in_array($audience, $k['audiences'], true)));

        return ['audience' => $audience, 'kpis' => $kpis];
    }

    /** @return array<string, float> */
    private function computeValues(): array
    {
        $cash = $this->balance(fn ($q) => $q->where('a.is_bank_account', true));
        $currentAssets = $this->balance(fn ($q) => $q->where('a.account_type', 'asset'));
        $currentLiabilities = -$this->balance(fn ($q) => $q->where('a.account_type', 'liability'));

        $revenue = -$this->fyBalance('revenue');         // revenue is credit-normal → negate net debit
        $expense = $this->fyBalance('expense');

        $arOutstanding = $this->subledgerOutstanding('customer_invoices');
        $apOutstanding = $this->subledgerOutstanding('vendor_invoices');

        $aging = $this->ar->aging()['totals'];
        $arTotal = round((float) ($aging['total'] ?? 0), 2);
        $overdue = round($arTotal - (float) ($aging['current'] ?? 0), 2);

        $netProfit = round($revenue - $expense, 2);

        return [
            'cash_position' => $cash,
            'current_ratio' => $currentLiabilities > 0 ? round($currentAssets / $currentLiabilities, 2) : 0,
            'ar_outstanding' => $arOutstanding,
            'overdue_ar_pct' => $arTotal > 0 ? round($overdue / $arTotal * 100, 2) : 0,
            'collection_ratio' => $arTotal > 0 ? round((1 - $overdue / $arTotal) * 100, 2) : 100,
            'ap_outstanding' => $apOutstanding,
            'revenue_ytd' => $revenue,
            'net_profit_ytd' => $netProfit,
            'gross_margin_pct' => $revenue > 0 ? round($netProfit / $revenue * 100, 2) : 0,
            'expense_ratio_pct' => $revenue > 0 ? round($expense / $revenue * 100, 2) : 0,
        ];
    }

    /** Net debit balance (Σ base_debit − base_credit) for a filtered account set. */
    private function balance(callable $filter): float
    {
        $q = DB::table('gl_transactions as g')->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id');
        $filter($q);

        return round((float) ($q->selectRaw('SUM(g.base_debit - g.base_credit) bal')->value('bal') ?? 0), 2);
    }

    /** Net debit balance of an account type within the current fiscal year. */
    private function fyBalance(string $accountType): float
    {
        $year = DB::table('fiscal_years')->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())->first();

        return $this->balance(function ($q) use ($accountType, $year): void {
            $q->where('a.account_type', $accountType);
            if ($year !== null) {
                $q->whereBetween('g.transaction_date', [$year->start_date, $year->end_date]);
            }
        });
    }

    private function subledgerOutstanding(string $table): float
    {
        return round((float) (DB::table($table)->where('status', 'posted')
            ->selectRaw('SUM(total_amount - amount_paid) bal')->value('bal') ?? 0), 2);
    }

    private function status(float $value, ?float $target, string $direction): string
    {
        if ($target === null) {
            return 'neutral';
        }

        return $direction === 'higher_better'
            ? ($value >= $target ? 'on_track' : 'off_track')
            : ($value <= $target ? 'on_track' : 'off_track');
    }
}
