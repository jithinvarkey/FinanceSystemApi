<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\GlTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F20 — Bank statement import. Parses a CSV bank statement into signed
 * statement lines and auto-matches them to GL transactions on the same bank
 * account, so reconciliation starts from "what the bank says" rather than a
 * blank slate.
 */
final class BankStatementImportService
{
    /** Match window: a statement line may clear a GL txn dated within ±N days. */
    private const MATCH_DAYS = 7;

    /**
     * Parse a CSV statement and store its lines. Recognised headers (case-
     * insensitive): date; description|details|narrative; amount|value (signed)
     * OR debit|withdrawal + credit|deposit; balance.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importCsv(ChartOfAccount $account, string $csv, int $userId): array
    {
        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            throw new FinanceRuleException('No data rows found in the statement.');
        }

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $account, $userId, &$imported, &$skipped): void {
            foreach ($rows as $row) {
                if ($row['date'] === null || $row['amount'] === null) {
                    $skipped++;
                    continue;
                }
                BankStatementLine::query()->create([
                    'bank_account_id' => $account->id,
                    'txn_date' => $row['date'],
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                    'amount' => $row['amount'],
                    'balance' => $row['balance'],
                    'created_by' => $userId,
                ]);
                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Statement lines for the account, each with a single suggested GL match
     * (an uncleared, unmatched GL transaction of equal signed amount within the
     * date window) when exactly one candidate exists.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(ChartOfAccount $account): array
    {
        $statementLines = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->orderBy('txn_date')->orderBy('id')
            ->get();

        $clearedIds = DB::table('bank_reconciliation_lines')->pluck('gl_transaction_id')->flip();
        $alreadyMatched = $statementLines->pluck('matched_gl_transaction_id')->filter()->flip();

        return $statementLines->map(function (BankStatementLine $line) use ($account, $clearedIds, $alreadyMatched): array {
            $suggestion = null;
            if ($line->matched_gl_transaction_id === null) {
                $suggestion = $this->suggestMatch($account, $line, $clearedIds, $alreadyMatched);
            }

            return [
                'id' => $line->id,
                'date' => $line->txn_date?->toDateString(),
                'description' => $line->description,
                'reference' => $line->reference,
                'amount' => (float) $line->amount,
                'matched_gl_transaction_id' => $line->matched_gl_transaction_id,
                'suggested_gl_transaction_id' => $suggestion,
            ];
        })->all();
    }

    /**
     * Auto-match every unmatched line that has exactly one confident GL
     * candidate. Returns the number newly matched.
     */
    public function autoMatch(ChartOfAccount $account): int
    {
        $matched = 0;
        $clearedIds = DB::table('bank_reconciliation_lines')->pluck('gl_transaction_id')->flip();

        $lines = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->whereNull('matched_gl_transaction_id')
            ->orderBy('txn_date')->orderBy('id')
            ->get();

        $usedThisRun = collect();

        foreach ($lines as $line) {
            $already = $usedThisRun->flip();
            $candidate = $this->suggestMatch($account, $line, $clearedIds, $already);
            if ($candidate !== null) {
                $line->update(['matched_gl_transaction_id' => $candidate]);
                $usedThisRun->push($candidate);
                $matched++;
            }
        }

        return $matched;
    }

    /** GL transaction ids matched by the imported statement (drive reconcile). */
    public function matchedGlIds(ChartOfAccount $account): array
    {
        return BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->whereNotNull('matched_gl_transaction_id')
            ->pluck('matched_gl_transaction_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    public function clear(ChartOfAccount $account): int
    {
        return BankStatementLine::query()->where('bank_account_id', $account->id)->delete();
    }

    /**
     * The single uncleared, unmatched GL transaction whose signed movement
     * equals the line amount within the date window — or null if none/ambiguous.
     *
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $clearedIds
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $excludeIds
     */
    private function suggestMatch(ChartOfAccount $account, BankStatementLine $line, $clearedIds, $excludeIds): ?int
    {
        $date = Carbon::parse((string) $line->txn_date);
        $target = round((float) $line->amount, 2);

        $candidates = GlTransaction::query()
            ->where('account_id', $account->id)
            ->whereDate('transaction_date', '>=', $date->copy()->subDays(self::MATCH_DAYS)->toDateString())
            ->whereDate('transaction_date', '<=', $date->copy()->addDays(self::MATCH_DAYS)->toDateString())
            ->get(['id', 'base_debit', 'base_credit'])
            ->reject(fn ($t) => $clearedIds->has($t->id) || $excludeIds->has($t->id))
            ->filter(fn ($t): bool => round((float) $t->base_debit - (float) $t->base_credit, 2) === $target)
            ->values();

        // Confident only when there is exactly one viable candidate.
        return $candidates->count() === 1 ? (int) $candidates->first()->id : null;
    }

    /**
     * @return list<array{date: ?string, description: ?string, reference: ?string, amount: ?float, balance: ?float}>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $header = array_map(fn (string $h): string => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $col = fn (array $names): ?int => $this->firstIndex($header, $names);

        $iDate = $col(['date', 'txn date', 'transaction date', 'value date']);
        $iDesc = $col(['description', 'details', 'narrative', 'memo']);
        $iRef = $col(['reference', 'ref']);
        $iAmount = $col(['amount', 'value']);
        $iDebit = $col(['debit', 'withdrawal', 'dr', 'paid out']);
        $iCredit = $col(['credit', 'deposit', 'cr', 'paid in']);
        $iBalance = $col(['balance']);

        $out = [];
        foreach ($lines as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $cells = str_getcsv($raw);
            $get = fn (?int $i): ?string => ($i !== null && isset($cells[$i]) && trim($cells[$i]) !== '') ? trim($cells[$i]) : null;

            $amount = null;
            if ($iAmount !== null && $get($iAmount) !== null) {
                $amount = $this->num($get($iAmount));
            } elseif ($iDebit !== null || $iCredit !== null) {
                $amount = round(($this->num($get($iCredit)) ?? 0) - ($this->num($get($iDebit)) ?? 0), 2);
            }

            $out[] = [
                'date' => $this->date($get($iDate)),
                'description' => $get($iDesc),
                'reference' => $get($iRef),
                'amount' => $amount,
                'balance' => $iBalance !== null ? $this->num($get($iBalance)) : null,
            ];
        }

        return $out;
    }

    /** @param list<string> $header @param list<string> $names */
    private function firstIndex(array $header, array $names): ?int
    {
        foreach ($names as $n) {
            $i = array_search($n, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }

        return null;
    }

    private function num(?string $v): ?float
    {
        if ($v === null) {
            return null;
        }
        $clean = str_replace([',', ' ', 'SAR', 'SR'], '', $v);
        // Parenthesised negatives, e.g. (150.00).
        if (preg_match('/^\((.*)\)$/', $clean, $m) === 1) {
            $clean = '-'.$m[1];
        }

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    private function date(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
