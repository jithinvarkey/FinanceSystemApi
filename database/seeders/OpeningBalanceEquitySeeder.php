<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

/**
 * P2.9b — a dedicated "Opening Balance Equity" account (21199), the contra side
 * for cutover sub-ledger items and the default plug for the GL trial balance.
 * Seeded under the existing 211 Equity header so it lives in the client's real
 * chart without editing the imported COA JSON.
 */
final class OpeningBalanceEquitySeeder extends Seeder
{
    /** Resolvable by code across the app. */
    public const CODE = '21199';

    public function run(): void
    {
        $parent = ChartOfAccount::query()->where('code', '211')->first();

        ChartOfAccount::query()->updateOrCreate(
            ['code' => self::CODE],
            [
                'name' => 'Opening Balance Equity',
                'account_type' => 'equity',
                'normal_balance' => 'credit',
                'level' => $parent ? $parent->level + 1 : 2,
                'parent_id' => $parent?->id,
                'is_postable' => true,
                'status' => 'active',
            ],
        );
    }
}
