<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * FIN-0001 — Imports Diamond's live chart of accounts.
 *
 * Source: database/data/chart_of_accounts.json, generated from the client's
 * "CHART OF ACCOUNT.xlsx". The hierarchy is derived from the account-code
 * prefixes (a node's parent is the longest existing code that prefixes it);
 * account type comes from the root branch (1=asset, 2=liability/equity,
 * 3=revenue, 4=expense; 211* = equity). Records are pre-sorted parents-first.
 */
final class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/chart_of_accounts.json');

        if (! is_file($path)) {
            throw new RuntimeException("Chart of accounts data file not found: {$path}");
        }

        /** @var list<array<string, mixed>> $records */
        $records = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // code => id, so each child can resolve its parent (parents come first).
        $idByCode = [];

        foreach ($records as $row) {
            $parentId = $row['parent_code'] !== null
                ? ($idByCode[$row['parent_code']] ?? null)
                : null;

            $account = ChartOfAccount::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'account_type' => $row['account_type'],
                    'normal_balance' => $row['normal_balance'],
                    'parent_id' => $parentId,
                    'level' => $row['level'],
                    'is_postable' => $row['is_postable'],
                    'is_bank_account' => $row['is_bank_account'],
                    'is_control_account' => $row['is_control_account'] ?? false,
                    'status' => 'active',
                ],
            );

            $idByCode[$row['code']] = $account->id;
        }

        $this->command?->info('Imported '.count($records).' GL accounts.');
    }
}
