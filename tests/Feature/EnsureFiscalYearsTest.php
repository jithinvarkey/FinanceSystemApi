<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * finance:ensure-fiscal-years — fiscal calendar groundwork (2015 onward).
 */
final class EnsureFiscalYearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_every_year_and_twelve_monthly_periods(): void
    {
        $this->artisan('finance:ensure-fiscal-years', ['--from' => 2015, '--to' => 2026])->assertSuccessful();

        $this->assertSame(12, FiscalYear::query()->count());                 // 2015..2026
        $this->assertSame(12 * 12, FiscalPeriod::query()->count());          // 12 months each

        $fy2015 = FiscalYear::query()->where('code', 'FY2015')->first();
        $this->assertSame('2015-01-01', $fy2015->start_date->toDateString());
        $this->assertSame('2015-12-31', $fy2015->end_date->toDateString());

        $jan = FiscalPeriod::query()->where('fiscal_year_id', $fy2015->id)->where('period_number', 1)->first();
        $this->assertSame('Jan 2015', $jan->name);
        $this->assertSame('2015-01-31', $jan->end_date->toDateString());
    }

    public function test_command_is_idempotent(): void
    {
        $this->artisan('finance:ensure-fiscal-years', ['--from' => 2020, '--to' => 2022])->assertSuccessful();
        // Re-run the overlapping range — nothing duplicated.
        $this->artisan('finance:ensure-fiscal-years', ['--from' => 2021, '--to' => 2023])->assertSuccessful();

        $this->assertSame(4, FiscalYear::query()->count());                  // 2020..2023
        $this->assertSame(4 * 12, FiscalPeriod::query()->count());
    }
}
