<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (N5) — Profit-center accounting. Produces a profit-and-loss statement
 * grouped by cost centre (treated as the profit centre), using the cost_center_id
 * already carried on every GL line. No schema change — it rides on existing tags.
 */
final class ProfitCenterService
{
    /**
     * @return array{from: ?string, to: ?string, rows: list<array{cost_center: string, revenue: float, expense: float, profit: float, margin_pct: float}>, totals: array{revenue: float, expense: float, profit: float}}
     */
    public function statement(?Carbon $from, ?Carbon $to): array
    {
        $records = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->leftJoin('cost_centers as c', 'c.id', '=', 'g.cost_center_id')
            ->whereIn('a.account_type', ['revenue', 'expense'])
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('g.transaction_date', '<=', $to->toDateString()))
            ->selectRaw('COALESCE(c.name, ?) cc, a.account_type, g.base_debit, g.base_credit', ['(unallocated)'])
            ->get();

        $byCc = [];
        foreach ($records as $rec) {
            $cc = (string) $rec->cc;
            $byCc[$cc] ??= ['revenue' => 0.0, 'expense' => 0.0];
            if ($rec->account_type === 'revenue') {
                $byCc[$cc]['revenue'] = round($byCc[$cc]['revenue'] + ((float) $rec->base_credit - (float) $rec->base_debit), 2);
            } else {
                $byCc[$cc]['expense'] = round($byCc[$cc]['expense'] + ((float) $rec->base_debit - (float) $rec->base_credit), 2);
            }
        }

        $rows = [];
        $tRev = 0.0;
        $tExp = 0.0;
        foreach ($byCc as $cc => $v) {
            $profit = round($v['revenue'] - $v['expense'], 2);
            $rows[] = [
                'cost_center' => $cc, 'revenue' => $v['revenue'], 'expense' => $v['expense'], 'profit' => $profit,
                'margin_pct' => $v['revenue'] > 0 ? round($profit / $v['revenue'] * 100, 2) : 0,
            ];
            $tRev = round($tRev + $v['revenue'], 2);
            $tExp = round($tExp + $v['expense'], 2);
        }
        usort($rows, fn (array $a, array $b): int => $b['profit'] <=> $a['profit']);

        return [
            'from' => $from?->toDateString(), 'to' => $to?->toDateString(),
            'rows' => $rows,
            'totals' => ['revenue' => $tRev, 'expense' => $tExp, 'profit' => round($tRev - $tExp, 2)],
        ];
    }
}
