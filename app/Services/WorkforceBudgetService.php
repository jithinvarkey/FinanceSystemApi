<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkforceBudget;
use Illuminate\Support\Facades\DB;

/**
 * W8 — workforce budget vs actual. Budgeted amounts per category are compared to
 * the actuals derived from posted payroll runs and benefit costs.
 */
final class WorkforceBudgetService
{
    public const CATEGORIES = ['salary', 'gosi', 'eosb', 'bonus', 'benefit', 'total'];

    public function __construct(private readonly WorkforceReportService $workforce)
    {
    }

    /**
     * @return array{year: int, rows: list<array{category: string, budget: float, actual: float, variance: float, used_pct: float}>}
     */
    public function comparison(int $year): array
    {
        $budgets = WorkforceBudget::query()->where('year', $year)->pluck('amount', 'category');
        $actuals = $this->actuals($year);

        $rows = [];
        foreach (self::CATEGORIES as $cat) {
            $budget = round((float) ($budgets[$cat] ?? 0), 2);
            $actual = round((float) ($actuals[$cat] ?? 0), 2);
            $rows[] = [
                'category' => $cat, 'budget' => $budget, 'actual' => $actual,
                'variance' => round($budget - $actual, 2),
                'used_pct' => $budget > 0 ? round($actual / $budget * 100, 1) : 0,
            ];
        }

        return ['year' => $year, 'rows' => $rows];
    }

    public function setBudgets(int $year, array $items): void
    {
        foreach ($items as $cat => $amount) {
            if (! in_array($cat, self::CATEGORIES, true)) {
                continue;
            }
            WorkforceBudget::query()->updateOrCreate(['year' => $year, 'category' => $cat], ['amount' => round((float) $amount, 2)]);
        }
    }

    /** @return array<string, float> */
    private function actuals(int $year): array
    {
        $a = $this->workforce->analytics($year)['summary'];

        $bonus = round((float) DB::table('payroll_run_lines as l')
            ->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')
            ->where('r.status', 'posted')->where('r.run_type', 'off_cycle')->where('r.period_year', $year)
            ->sum('l.gross'), 2);

        $benefit = round((float) DB::table('benefit_costs')
            ->whereIn('kind', ['utilization', 'expense'])->whereYear('cost_date', $year)
            ->sum('amount'), 2);

        return [
            'salary' => $a['gross'], 'gosi' => $a['gosi_employer'], 'eosb' => $a['eosb'],
            'total' => $a['total_cost'], 'bonus' => $bonus, 'benefit' => $benefit,
        ];
    }
}
