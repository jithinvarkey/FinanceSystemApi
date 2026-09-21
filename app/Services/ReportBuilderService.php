<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * N4 — Self-service reporting. Runs an ad-hoc/saved report definition over the
 * GL: groups posted activity by an ordered list of dimensions, aggregates a
 * measure, and supports drill-through to the underlying transaction lines.
 *
 * Grouping + formatting happen in PHP after a filtered fetch so the same code
 * runs on MySQL (live) and SQLite (tests).
 */
final class ReportBuilderService
{
    public const DIMENSIONS = ['month', 'account_type', 'account', 'cost_center', 'dimension'];
    private const MEASURES = ['net', 'debit', 'credit'];

    /**
     * @param  list<string>  $groupBy  ordered dimensions
     * @param  array{from?: ?string, to?: ?string, account_type?: ?string}  $filters
     * @return array{measure: string, group_by: list<string>, rows: list<array{keys: array<string,string>, label: string, value: float}>, grand_total: float, row_count: int}
     */
    public function run(string $measure, array $groupBy, array $filters): array
    {
        if (! in_array($measure, self::MEASURES, true)) {
            throw new FinanceRuleException("Unknown measure '{$measure}'.");
        }
        $groupBy = array_values(array_filter($groupBy, fn (string $d): bool => in_array($d, self::DIMENSIONS, true)));
        if ($groupBy === []) {
            throw new FinanceRuleException('Pick at least one grouping dimension.');
        }

        $records = $this->fetch($filters);

        $groups = [];
        $grand = 0.0;
        foreach ($records as $rec) {
            $keys = [];
            foreach ($groupBy as $dim) {
                $keys[$dim] = $this->dimValue($rec, $dim);
            }
            $hash = implode('||', $keys);
            $value = $this->measureValue($rec, $measure);
            $groups[$hash]['keys'] = $keys;
            $groups[$hash]['value'] = round(($groups[$hash]['value'] ?? 0) + $value, 2);
            $grand = round($grand + $value, 2);
        }

        $rows = array_map(static fn (array $g): array => [
            'keys' => $g['keys'], 'label' => implode(' · ', array_values($g['keys'])), 'value' => $g['value'],
        ], array_values($groups));
        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return ['measure' => $measure, 'group_by' => $groupBy, 'rows' => $rows, 'grand_total' => $grand, 'row_count' => count($rows)];
    }

    /**
     * Drill-through: the posted GL lines matching a row's dimension key set.
     *
     * @param  array<string,string>  $keys
     * @return list<array<string,mixed>>
     */
    public function drillThrough(array $keys, array $filters): array
    {
        $records = $this->fetch($filters);

        return array_values(array_filter(array_map(function ($rec) use ($keys): ?array {
            foreach ($keys as $dim => $expected) {
                if ($this->dimValue($rec, $dim) !== $expected) {
                    return null;
                }
            }

            return [
                'date' => (string) $rec->transaction_date, 'account_code' => $rec->account_code, 'account_name' => $rec->account_name,
                'description' => $rec->description, 'debit' => round((float) $rec->base_debit, 2), 'credit' => round((float) $rec->base_credit, 2),
                'batch_number' => $rec->batch_number,
            ];
        }, $records)));
    }

    /** @return list<\stdClass> */
    private function fetch(array $filters): array
    {
        return DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->leftJoin('cost_centers as c', 'c.id', '=', 'g.cost_center_id')
            ->leftJoin('gl_dimensions as d', 'd.id', '=', 'g.dimension_id')
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('g.transaction_date', '>=', Carbon::parse($filters['from'])->toDateString()))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('g.transaction_date', '<=', Carbon::parse($filters['to'])->toDateString()))
            ->when(! empty($filters['account_type']), fn ($q) => $q->where('a.account_type', $filters['account_type']))
            ->selectRaw('g.transaction_date, a.account_type, a.code account_code, a.name account_name, c.name cost_center, d.name dimension, g.base_debit, g.base_credit, g.description, g.batch_number')
            ->get()->all();
    }

    private function dimValue(object $rec, string $dim): string
    {
        return match ($dim) {
            'month' => substr((string) $rec->transaction_date, 0, 7),
            'account_type' => (string) $rec->account_type,
            'account' => $rec->account_code.' '.$rec->account_name,
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
