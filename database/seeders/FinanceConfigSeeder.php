<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\TaxCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the non-COA starter configuration for Diamond Insurance Broker (KSA):
 * SAR base currency, Saudi VAT 15% (ZATCA), and cost centres. The chart of
 * accounts itself is imported separately by ChartOfAccountSeeder (the client's
 * live COA), which must run before this seeder so the VAT account links resolve.
 */
final class FinanceConfigSeeder extends Seeder
{
    public function run(): void
    {
        // --- Currencies (SAR base for Saudi Arabia) ---
        Currency::query()->firstOrCreate(['code' => 'SAR'], [
            'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active',
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => false, 'status' => 'active',
        ]);
        Currency::query()->firstOrCreate(['code' => 'AED'], [
            'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimal_places' => 2, 'is_base' => false, 'status' => 'active',
        ]);

        // --- Saudi VAT (ZATCA) — linked to the live COA VAT accounts ---
        // 1207 = Legal VAT Receivables (input), 223 = VAT Payable (output).
        $inputVat = ChartOfAccount::query()->where('code', '1207')->first();
        $outputVat = ChartOfAccount::query()->where('code', '223')->first();

        // VAT15 is the LIVE default (standard rate from 2020-07-01).
        TaxCode::query()->firstOrCreate(['code' => 'VAT15'], [
            'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0,
            'input_account_id' => $inputVat?->id, 'output_account_id' => $outputVat?->id,
            'is_recoverable' => true, 'status' => 'active',
        ]);
        // VAT5 — historical Saudi standard rate (2018-01-01 → 2020-06-30). Kept
        // for importing pre-July-2020 documents (see broker doc §16). Same VAT
        // accounts as VAT15; rate is what differs by document date.
        TaxCode::query()->firstOrCreate(['code' => 'VAT5'], [
            'name' => 'Saudi VAT 5% (2018–Jun 2020)', 'tax_type' => 'both', 'rate' => 5.0,
            'input_account_id' => $inputVat?->id, 'output_account_id' => $outputVat?->id,
            'is_recoverable' => true, 'status' => 'active',
        ]);
        TaxCode::query()->firstOrCreate(['code' => 'VAT0'], [
            'name' => 'Zero-rated', 'tax_type' => 'both', 'rate' => 0.0,
            'is_recoverable' => true, 'status' => 'active',
        ]);
        // NOVAT — out of scope: pre-2018 (no VAT regime) or pre-registration.
        // Distinct from zero-rated for VAT reporting; produces no VAT legs.
        TaxCode::query()->firstOrCreate(['code' => 'NOVAT'], [
            'name' => 'No VAT (pre-2018 / out of scope)', 'tax_type' => 'both', 'rate' => 0.0,
            'is_recoverable' => false, 'status' => 'active',
        ]);
        TaxCode::query()->firstOrCreate(['code' => 'EXEMPT'], [
            'name' => 'VAT Exempt', 'tax_type' => 'both', 'rate' => 0.0,
            'is_recoverable' => false, 'status' => 'active',
        ]);

        // --- Cost centers ---
        foreach ([
            ['code' => 'CC-BROKING', 'name' => 'Broking Operations'],
            ['code' => 'CC-CLAIMS', 'name' => 'Claims Handling'],
            ['code' => 'CC-FIN', 'name' => 'Finance & Admin'],
            ['code' => 'CC-IT', 'name' => 'Information Technology'],
        ] as $cc) {
            CostCenter::query()->firstOrCreate(['code' => $cc['code']], [...$cc, 'status' => 'active']);
        }

        // --- Fiscal year 2026 with 12 open monthly periods (postings need an open period) ---
        $year = FiscalYear::query()->firstOrCreate(
            ['code' => 'FY2026'],
            ['name' => 'Fiscal Year 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open'],
        );

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create(2026, $month, 1);
            FiscalPeriod::query()->firstOrCreate(
                ['fiscal_year_id' => $year->id, 'period_number' => $month],
                [
                    'name' => $start->format('M Y'),
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                ],
            );
        }
    }
}
