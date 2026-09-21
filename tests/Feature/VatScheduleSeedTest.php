<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TaxCode;
use App\Services\Tax\SaudiVatSchedule;
use Database\Seeders\FinanceConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The historical VAT codes are seeded and the schedule resolves to them.
 */
final class VatScheduleSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceConfigSeeder::class);
    }

    public function test_historical_vat_codes_are_seeded(): void
    {
        foreach (['VAT15' => '15.0000', 'VAT5' => '5.0000', 'VAT0' => '0.0000', 'NOVAT' => '0.0000'] as $code => $rate) {
            $this->assertDatabaseHas('tax_codes', ['code' => $code, 'rate' => $rate]);
        }
    }

    public function test_schedule_resolves_to_the_seeded_code(): void
    {
        $this->assertSame('VAT5', SaudiVatSchedule::resolve(Carbon::parse('2019-05-01'))?->code);
        $this->assertSame('VAT15', SaudiVatSchedule::resolve(Carbon::parse('2026-06-15'))?->code);
        $this->assertSame('NOVAT', SaudiVatSchedule::resolve(Carbon::parse('2016-03-10'))?->code);

        // VAT5 carries the same VAT accounts as VAT15 (only the rate differs).
        $vat5 = TaxCode::query()->where('code', 'VAT5')->first();
        $vat15 = TaxCode::query()->where('code', 'VAT15')->first();
        $this->assertSame($vat15->input_account_id, $vat5->input_account_id);
        $this->assertSame($vat15->output_account_id, $vat5->output_account_id);
    }
}
