<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\LineOfBusiness;
use App\Models\Product;
use App\Models\TaxCode;
use Illuminate\Database\Seeder;

/**
 * P4.17 — Starter lines of business and products for the KSA broker. General
 * business (Motor/Medical/General) is VAT-standard; Life is VAT-exempt. Runs
 * after FinanceConfigSeeder so VAT codes and the COA exist. Idempotent.
 */
final class PolicyReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $vat15 = TaxCode::query()->where('code', 'VAT15')->value('id');
        $exempt = TaxCode::query()->where('code', 'EXEMPT')->value('id');

        // A commission/brokerage income account (first postable revenue account).
        $commissionAccount = ChartOfAccount::query()
            ->where('account_type', 'revenue')
            ->where('is_postable', true)
            ->orderBy('code')
            ->value('id');

        /** @var array<string, array{name: string, is_life: bool, products: list<array{code: string, name: string, rate: float, life?: bool}>}> $catalogue */
        $catalogue = [
            'MOTOR' => ['name' => 'Motor', 'is_life' => false, 'products' => [
                ['code' => 'MOT-TPL', 'name' => 'Motor — Third Party Liability', 'rate' => 10.0],
                ['code' => 'MOT-COMP', 'name' => 'Motor — Comprehensive', 'rate' => 12.5],
            ]],
            'MEDICAL' => ['name' => 'Medical', 'is_life' => false, 'products' => [
                ['code' => 'MED-CORP', 'name' => 'Medical — Corporate', 'rate' => 7.5],
                ['code' => 'MED-SME', 'name' => 'Medical — SME', 'rate' => 10.0],
            ]],
            'GENERAL' => ['name' => 'General', 'is_life' => false, 'products' => [
                ['code' => 'GEN-PROP', 'name' => 'Property', 'rate' => 17.5],
                ['code' => 'GEN-MAR', 'name' => 'Marine', 'rate' => 15.0],
            ]],
            'LIFE' => ['name' => 'Life', 'is_life' => true, 'products' => [
                ['code' => 'LIFE-TERM', 'name' => 'Term Life', 'rate' => 20.0, 'life' => true],
                ['code' => 'LIFE-GROUP', 'name' => 'Group Life', 'rate' => 12.0, 'life' => true],
            ]],
        ];

        foreach ($catalogue as $code => $def) {
            $lob = LineOfBusiness::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $def['name'], 'is_life' => $def['is_life'], 'status' => 'active'],
            );

            foreach ($def['products'] as $p) {
                Product::query()->updateOrCreate(
                    ['code' => $p['code']],
                    [
                        'lob_id' => $lob->id,
                        'name' => $p['name'],
                        'default_commission_rate' => $p['rate'],
                        // Life is VAT-exempt; general business is standard-rated.
                        'default_tax_code_id' => ($p['life'] ?? false) ? $exempt : $vat15,
                        'commission_revenue_account_id' => $commissionAccount,
                        'status' => 'active',
                    ],
                );
            }
        }
    }
}
