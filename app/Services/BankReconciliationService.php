<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\BankReconciliation;
use App\Models\ChartOfAccount;
use App\Models\GlTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P5 — Bank reconciliation: reconcile a bank GL account's book balance to a
 * bank statement. The operator clears the GL transactions that appear on the
 * statement; cleared total (opening + cleared movement) must equal the
 * statement balance. The immutable GL is never mutated — cleared lines live in
 * `bank_reconciliation_lines`.
 */
final class BankReconciliationService
{
    /**
     * Bank GL accounts with their book balance and last reconciliation.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return ChartOfAccount::query()
            ->where('is_bank_account', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(function (ChartOfAccount $a): array {
                $last = BankReconciliation::query()->where('bank_account_id', $a->id)->latest('statement_date')->first();

                return [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'book_balance' => $this->bookBalance($a->id),
                    'last_reconciled' => $last?->statement_date?->toDateString(),
                    'last_balance' => $last !== null ? (float) $last->statement_balance : null,
                ];
            })
            ->all();
    }

    /**
     * The bank ledger: every GL transaction on the account with a cleared flag,
     * running book balance, plus the cleared / uncleared summary.
     *
     * @return array{
     *     account: array<string, mixed>,
     *     opening_balance: float,
     *     book_balance: float,
     *     cleared_balance: float,
     *     uncleared_total: float,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function ledger(ChartOfAccount $account): array
    {
        $clearedIds = DB::table('bank_reconciliation_lines')->pluck('gl_transaction_id')->flip();

        $txns = GlTransaction::query()
            ->where('account_id', $account->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'transaction_date', 'description', 'batch_number', 'base_debit', 'base_credit']);

        $balance = 0.0;
        $clearedBalance = 0.0;
        $rows = [];

        foreach ($txns as $t) {
            $movement = round((float) $t->base_debit - (float) $t->base_credit, 2);
            $balance = round($balance + $movement, 2);
            $cleared = $clearedIds->has($t->id);
            if ($cleared) {
                $clearedBalance = round($clearedBalance + $movement, 2);
            }

            $rows[] = [
                'id' => $t->id,
                'date' => $t->transaction_date?->toDateString(),
                'reference' => $t->batch_number,
                'description' => $t->description,
                'debit' => round((float) $t->base_debit, 2),
                'credit' => round((float) $t->base_credit, 2),
                'balance' => $balance,
                'cleared' => $cleared,
            ];
        }

        $opening = (float) (BankReconciliation::query()->where('bank_account_id', $account->id)->latest('statement_date')->value('statement_balance') ?? 0);

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'opening_balance' => round($opening, 2),
            'book_balance' => round($balance, 2),
            'cleared_balance' => round($clearedBalance, 2),
            'uncleared_total' => round($balance - $clearedBalance, 2),
            'rows' => $rows,
        ];
    }

    /**
     * Reconcile: clear the given GL transactions and record the reconciliation.
     * Recomputes server-side and requires the statement to balance to zero.
     *
     * @param array<string, mixed> $data
     */
    public function reconcile(ChartOfAccount $account, array $data, int $userId): BankReconciliation
    {
        $clearedIds = array_values(array_unique(array_map('intval', $data['cleared_transaction_ids'] ?? [])));
        $statementBalance = round((float) $data['statement_balance'], 2);
        $statementDate = Carbon::parse($data['statement_date']);

        // Every cleared id must be a GL transaction on this account and not already cleared.
        $valid = GlTransaction::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $clearedIds)
            ->pluck('id')->all();
        if (count($valid) !== count($clearedIds)) {
            throw new FinanceRuleException('A selected transaction does not belong to this bank account.');
        }
        $already = DB::table('bank_reconciliation_lines')->whereIn('gl_transaction_id', $clearedIds)->exists();
        if ($already) {
            throw new FinanceRuleException('A selected transaction has already been reconciled.');
        }

        $opening = (float) (BankReconciliation::query()->where('bank_account_id', $account->id)->latest('statement_date')->value('statement_balance') ?? 0);

        $clearedMovement = (float) GlTransaction::query()
            ->whereIn('id', $clearedIds)
            ->selectRaw('SUM(base_debit - base_credit) m')
            ->value('m');

        $clearedTotal = round($opening + $clearedMovement, 2);
        $difference = round($statementBalance - $clearedTotal, 2);

        if (abs($difference) >= 0.01) {
            throw new FinanceRuleException("The reconciliation is out of balance by {$difference}. Clear the items that match the statement until the difference is zero.");
        }

        return DB::transaction(function () use ($account, $statementDate, $statementBalance, $opening, $clearedTotal, $clearedIds, $userId): BankReconciliation {
            $reconciliation = BankReconciliation::query()->create([
                'bank_account_id' => $account->id,
                'statement_date' => $statementDate->toDateString(),
                'statement_balance' => $statementBalance,
                'opening_balance' => round($opening, 2),
                'cleared_total' => $clearedTotal,
                'difference' => 0.0,
                'status' => 'completed',
                'created_by' => $userId,
                'completed_at' => now(),
            ]);

            $reconciliation->lines()->createMany(
                array_map(fn (int $id): array => ['gl_transaction_id' => $id], $clearedIds),
            );

            return $reconciliation->load('bankAccount');
        });
    }

    /**
     * Past reconciliations for an account.
     *
     * @return list<array<string, mixed>>
     */
    public function history(ChartOfAccount $account): array
    {
        return BankReconciliation::query()
            ->where('bank_account_id', $account->id)
            ->withCount('lines')
            ->latest('statement_date')
            ->get()
            ->map(fn (BankReconciliation $r): array => [
                'id' => $r->id,
                'statement_date' => $r->statement_date?->toDateString(),
                'statement_balance' => $r->statement_balance,
                'cleared_total' => $r->cleared_total,
                'items' => $r->lines_count,
                'completed_at' => $r->completed_at?->toIso8601String(),
            ])
            ->all();
    }

    private function bookBalance(int $accountId): float
    {
        return round((float) GlTransaction::query()
            ->where('account_id', $accountId)
            ->selectRaw('SUM(base_debit - base_credit) bal')
            ->value('bal'), 2);
    }
}
