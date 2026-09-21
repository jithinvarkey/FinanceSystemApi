<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use Illuminate\Support\Facades\DB;

/**
 * P8 — Budgeting. Stores annual targets per account and compares them to the
 * GL actuals for the fiscal year (in each account's natural direction — expense
 * spend, revenue earned — so a positive budget meets a positive actual).
 */
final class BudgetService
{
    /** @param array<string, mixed> $data */
    public function save(array $data, int $userId, ?Budget $budget = null): Budget
    {
        return DB::transaction(function () use ($data, $userId, $budget): Budget {
            $budget ??= new Budget();
            $budget->fill([
                'name' => $data['name'],
                'fiscal_year_id' => $data['fiscal_year_id'],
                'status' => $data['status'] ?? 'draft',
                'created_by' => $budget->created_by ?? $userId,
            ])->save();

            $budget->lines()->delete();
            foreach ($data['lines'] ?? [] as $line) {
                if (! isset($line['account_id'])) {
                    continue;
                }
                $budget->lines()->create([
                    'account_id' => (int) $line['account_id'],
                    'annual_amount' => round((float) ($line['annual_amount'] ?? 0), 2),
                ]);
            }

            return $budget->load('lines.account');
        });
    }

    /**
     * Budget vs GL actuals for the budget's fiscal year.
     *
     * @return array{fiscal_year: string, rows: list<array<string, mixed>>, totals: array{budget: string, actual: string, variance: string}}
     */
    public function vsActual(Budget $budget): array
    {
        $year = FiscalYear::query()->findOrFail($budget->fiscal_year_id);
        $budget->loadMissing('lines.account');

        $rows = [];
        $totalBudget = 0.0;
        $totalActual = 0.0;

        foreach ($budget->lines as $line) {
            $account = $line->account;
            $actual = $this->actual($account, (string) $year->start_date, (string) $year->end_date);
            $budgetAmt = (float) $line->annual_amount;
            $variance = round($budgetAmt - $actual, 2);

            $rows[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'budget' => number_format($budgetAmt, 2, '.', ''),
                'actual' => number_format($actual, 2, '.', ''),
                'variance' => number_format($variance, 2, '.', ''),
                'variance_pct' => $budgetAmt != 0.0 ? round($variance / $budgetAmt * 100, 1) : null,
            ];
            $totalBudget += $budgetAmt;
            $totalActual += $actual;
        }

        return [
            'fiscal_year' => $year->code,
            'rows' => $rows,
            'totals' => [
                'budget' => number_format($totalBudget, 2, '.', ''),
                'actual' => number_format($totalActual, 2, '.', ''),
                'variance' => number_format(round($totalBudget - $totalActual, 2), 2, '.', ''),
            ],
        ];
    }

    /** Account's movement in its natural direction over the period. */
    private function actual(ChartOfAccount $account, string $from, string $to): float
    {
        $sums = GlTransaction::query()
            ->where('account_id', $account->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(base_debit),0) d, COALESCE(SUM(base_credit),0) c')
            ->first();

        $debit = (float) ($sums->d ?? 0);
        $credit = (float) ($sums->c ?? 0);
        $type = $account->account_type instanceof \BackedEnum ? $account->account_type->value : (string) $account->account_type;

        return round(in_array($type, ['expense', 'asset'], true) ? $debit - $credit : $credit - $debit, 2);
    }
}
