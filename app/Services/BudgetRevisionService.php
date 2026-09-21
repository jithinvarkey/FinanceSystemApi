<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E9 — Budget revisions & transfers. Restates a single budget line, or moves
 * budget between two lines of the same budget (a virement), logging every change.
 */
final class BudgetRevisionService
{
    /** Restate one line to a new annual amount, logging the delta. */
    public function revise(BudgetLine $line, float $newAmount, string $reason, int $userId): BudgetRevision
    {
        $newAmount = round($newAmount, 2);
        if ($newAmount < 0) {
            throw new FinanceRuleException('A budget amount cannot be negative.');
        }

        return DB::transaction(function () use ($line, $newAmount, $reason, $userId): BudgetRevision {
            $delta = round($newAmount - (float) $line->annual_amount, 2);
            $line->update(['annual_amount' => $newAmount]);

            return BudgetRevision::query()->create([
                'budget_id' => $line->budget_id, 'budget_line_id' => $line->id, 'type' => 'revision',
                'counterpart_line_id' => null, 'delta' => $delta, 'new_amount' => $newAmount,
                'reason' => $reason, 'created_by' => $userId,
            ]);
        });
    }

    /**
     * Move $amount of budget from one line to another within the same budget.
     *
     * @return array{from: BudgetRevision, to: BudgetRevision}
     */
    public function transfer(BudgetLine $from, BudgetLine $to, float $amount, string $reason, int $userId): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new FinanceRuleException('Transfer amount must be positive.');
        }
        if ($from->id === $to->id) {
            throw new FinanceRuleException('Cannot transfer a budget line to itself.');
        }
        if ((int) $from->budget_id !== (int) $to->budget_id) {
            throw new FinanceRuleException('Both lines must belong to the same budget.');
        }
        if ((float) $from->annual_amount < $amount) {
            throw new FinanceRuleException('The source line does not have enough budget to transfer.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $reason, $userId): array {
            $fromNew = round((float) $from->annual_amount - $amount, 2);
            $toNew = round((float) $to->annual_amount + $amount, 2);
            $from->update(['annual_amount' => $fromNew]);
            $to->update(['annual_amount' => $toNew]);

            $outRow = BudgetRevision::query()->create([
                'budget_id' => $from->budget_id, 'budget_line_id' => $from->id, 'type' => 'transfer',
                'counterpart_line_id' => $to->id, 'delta' => -$amount, 'new_amount' => $fromNew,
                'reason' => $reason, 'created_by' => $userId,
            ]);
            $inRow = BudgetRevision::query()->create([
                'budget_id' => $to->budget_id, 'budget_line_id' => $to->id, 'type' => 'transfer',
                'counterpart_line_id' => $from->id, 'delta' => $amount, 'new_amount' => $toNew,
                'reason' => $reason, 'created_by' => $userId,
            ]);

            return ['from' => $outRow, 'to' => $inRow];
        });
    }

    /** @return Collection<int, BudgetRevision> */
    public function history(Budget $budget): Collection
    {
        return BudgetRevision::query()
            ->where('budget_id', $budget->id)
            ->with('line.account')
            ->latest('id')
            ->get();
    }
}
