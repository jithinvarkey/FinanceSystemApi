<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E8 — BI analytics cube + what-if. Pivots posted GL activity across whitelisted
 * dimensions (month / account type / cost centre / GL dimension) and measures
 * (net / debit / credit), and projects a simple growth-based what-if series.
 *
 * Grouping is done in PHP after a date-filtered fetch so it stays portable
 * across MySQL (live) and SQLite (tests) without DB-specific date functions.
 */
final class AnalyticsCubeService
{
    private const DIMENSIONS = ['month', 'account_type', 'cost_center', 'dimension'];
    private const MEASURES = ['net', 'debit', 'credit'];

    /**
     * Pivot GL activity. $col may be null for a single-axis (row-only) summary.
     *
     * @return array{measure: string, row_dim: string, col_dim: ?string, rows: list<string>, columns: list<string>, cells: array<string, array<string, float>>, row_totals: array<string, float>, col_totals: array<string, float>, grand_total: float}
     */
    public function pivot(string $measure, string $row, ?string $col, ?Carbon $from, ?Carbon $to): array
    {
        if (! in_array($measure, self::MEASURES, true)) {
            throw new FinanceRuleException("Unknown measure '{$measure}'.");
        }
        foreach (array_filter([$row, $col]) as $d) {
            if (! in_array($d, self::DIMENSIONS, true)) {
                throw new FinanceRuleException("Unknown dimension '{$d}'.");
            }
        }
        if ($col !== null && $col === $row) {
            throw new FinanceRuleException('Row and column dimensions must differ.');
        }

        $records = $this->fetch($from, $to);

        $cells = [];
        $rowTotals = [];
        $colTotals = [];
        $rowKeys = [];
        $colKeys = [];
        $grand = 0.0;

        foreach ($records as $rec) {
            $rk = $this->dimValue($rec, $row);
            $ck = $col === null ? '_' : $this->dimValue($rec, $col);
            $value = $this->measureValue($rec, $measure);

            $cells[$rk][$ck] = round(($cells[$rk][$ck] ?? 0) + $value, 2);
            $rowTotals[$rk] = round(($rowTotals[$rk] ?? 0) + $value, 2);
            $colTotals[$ck] = round(($colTotals[$ck] ?? 0) + $value, 2);
            $grand = round($grand + $value, 2);
            $rowKeys[$rk] = true;
            $colKeys[$ck] = true;
        }

        $rows = array_keys($rowKeys);
        sort($rows);
        $columns = array_keys($colKeys);
        sort($columns);

        return [
            'measure' => $measure, 'row_dim' => $row, 'col_dim' => $col,
            'rows' => $rows, 'columns' => $columns,
            'cells' => $cells, 'row_totals' => $rowTotals, 'col_totals' => $colTotals,
            'grand_total' => $grand,
        ];
    }

    /**
     * What-if revenue/expense projection: take the monthly net for a chosen
     * account type, then project $months forward applying $growthPct compounding
     * plus a flat $adjustment each projected month.
     *
     * @return array{actuals: list<array{month: string, value: float}>, projection: list<array{month: string, value: float}>, growth_pct: float, adjustment: float}
     */
    public function whatIf(string $accountType, float $growthPct, float $adjustment, int $months, ?Carbon $from, ?Carbon $to): array
    {
        $records = array_filter($this->fetch($from, $to), fn ($r) => $r->account_type === $accountType);

        $byMonth = [];
        foreach ($records as $rec) {
            $m = substr((string) $rec->transaction_date, 0, 7);
            $byMonth[$m] = round(($byMonth[$m] ?? 0) + $this->measureValue($rec, 'net'), 2);
        }
        ksort($byMonth);

        $actuals = [];
        foreach ($byMonth as $m => $v) {
            $actuals[] = ['month' => $m, 'value' => $v];
        }

        // Project from the average of the most recent up-to-3 actual months.
        $recent = array_slice(array_values($byMonth), -3);
        $base = $recent === [] ? 0.0 : array_sum($recent) / count($recent);
        $lastMonth = $actuals === [] ? Carbon::now()->startOfMonth() : Carbon::createFromFormat('Y-m', (string) array_key_last($byMonth))->startOfMonth();

        $factor = 1 + ($growthPct / 100);
        $months = max(1, min(24, $months));
        $projection = [];
        $value = $base;
        for ($i = 1; $i <= $months; $i++) {
            $value = round($value * $factor + $adjustment, 2);
            $projection[] = ['month' => $lastMonth->copy()->addMonths($i)->format('Y-m'), 'value' => $value];
        }

        return ['actuals' => $actuals, 'projection' => $projection, 'growth_pct' => round($growthPct, 2), 'adjustment' => round($adjustment, 2)];
    }

    /** @return list<\stdClass> raw posted-GL rows with dimension labels. */
    private function fetch(?Carbon $from, ?Carbon $to): array
    {
        return DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->leftJoin('cost_centers as c', 'c.id', '=', 'g.cost_center_id')
            ->leftJoin('gl_dimensions as d', 'd.id', '=', 'g.dimension_id')
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('g.transaction_date', '<=', $to->toDateString()))
            ->selectRaw('g.transaction_date, a.account_type, c.name cost_center, d.name dimension, g.base_debit, g.base_credit')
            ->get()
            ->all();
    }

    private function dimValue(object $rec, string $dim): string
    {
        return match ($dim) {
            'month' => substr((string) $rec->transaction_date, 0, 7),
            'account_type' => (string) $rec->account_type,
            'cost_center' => $rec->cost_center !== null ? (string) $rec->cost_center : '(none)',
            'dimension' => $rec->dimension !== null ? (string) $rec->dimension : '(none)',
            default => '(none)',
        };
    }

    private function measureValue(object $rec, string $measure): float
    {
        return match ($measure) {
            'debit' => (float) $rec->base_debit,
            'credit' => (float) $rec->base_credit,
            default => (float) $rec->base_debit - (float) $rec->base_credit,
        };
    }
}
