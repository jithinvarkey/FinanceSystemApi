<?php

declare(strict_types=1);

namespace App\Services\Import\Importers;

use App\Services\Import\BaseImporter;
use App\Services\Import\ResolvesImportReferences;
use App\Services\OpeningBalanceService;
use Database\Seeders\OpeningBalanceEquitySeeder;
use Illuminate\Support\Carbon;

/**
 * GL opening trial balance — one account per row. Each row posts its balance
 * against Opening Balance Equity at the cutover date (so every row is balanced
 * and the sheet as a whole nets through OBE). Import the chart of accounts'
 * non-sub-ledger balances here; bring AR/AP in via the Open AR / Open AP sheets
 * (don't key those control totals here too, or you'll double-count).
 */
final class OpeningTrialBalanceImporter extends BaseImporter
{
    use ResolvesImportReferences;

    public function __construct(private readonly OpeningBalanceService $service)
    {
    }

    public function key(): string
    {
        return 'opening-trial-balance';
    }

    public function label(): string
    {
        return 'Opening trial balance (GL)';
    }

    public function group(): string
    {
        return 'Opening balances';
    }

    public function order(): int
    {
        return 50;
    }

    public function columns(): array
    {
        return [
            ['name' => 'cutover_date', 'required' => true, 'hint' => 'Same date on every row, e.g. 2026-01-01'],
            ['name' => 'account_code', 'required' => true, 'hint' => 'Postable GL account code'],
            ['name' => 'debit', 'required' => false],
            ['name' => 'credit', 'required' => false],
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $cutover = $this->date($row, 'cutover_date');
        $accCode = $this->str($row, 'account_code');
        $account = $this->findAccount($accCode);
        $debit = round($this->num($row, 'debit') ?? 0, 2);
        $credit = round($this->num($row, 'credit') ?? 0, 2);

        if (! $cutover) {
            $errors[] = 'cutover_date is required (valid date)';
        }
        if (! $accCode) {
            $errors[] = 'account_code is required';
        } elseif (! $account) {
            $errors[] = "account_code '{$accCode}' not found";
        }
        if ($debit > 0 && $credit > 0) {
            $errors[] = 'a row has either a debit or a credit, not both';
        }
        if ($debit <= 0 && $credit <= 0) {
            $errors[] = 'enter a debit or a credit amount';
        }

        return [
            'errors' => $errors,
            'data' => ['cutover_date' => $cutover, 'account_id' => $account?->id, 'debit' => $debit, 'credit' => $credit],
        ];
    }

    public function commitRow(array $data, int $userId): string
    {
        $obe = $this->findAccount(OpeningBalanceEquitySeeder::CODE);

        $journal = $this->service->post(
            Carbon::parse($data['cutover_date']),
            [['account_id' => $data['account_id'], 'debit' => $data['debit'], 'credit' => $data['credit']]],
            $userId,
            $obe?->id,
            'Opening balance import',
        );

        return $journal->journal_number;
    }
}
