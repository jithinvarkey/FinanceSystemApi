<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tax\SaudiVatSchedule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The KSA standard-VAT timeline — exact regime boundaries (date-effective VAT
 * for the 2015→ historical import).
 */
final class SaudiVatScheduleTest extends TestCase
{
    /**
     * @return list<array{string, string, float}>
     */
    public static function dates(): array
    {
        return [
            // pre-2018: no VAT regime
            ['2015-06-15', 'NOVAT', 0.0],
            ['2017-12-31', 'NOVAT', 0.0],
            // 5% era — inclusive of the start, exclusive of the increase
            ['2018-01-01', 'VAT5', 5.0],
            ['2019-07-01', 'VAT5', 5.0],
            ['2020-06-30', 'VAT5', 5.0],
            // 15% from the increase date onward (live default)
            ['2020-07-01', 'VAT15', 15.0],
            ['2026-06-15', 'VAT15', 15.0],
        ];
    }

    #[DataProvider('dates')]
    public function test_resolves_standard_code_and_rate_by_date(string $date, string $code, float $rate): void
    {
        $d = Carbon::parse($date);

        $this->assertSame($code, SaudiVatSchedule::codeFor($d), "code on {$date}");
        $this->assertSame($rate, SaudiVatSchedule::rateFor($d), "rate on {$date}");
    }

    public function test_boundaries_are_exact(): void
    {
        // The day before each transition still belongs to the earlier regime.
        $this->assertSame('NOVAT', SaudiVatSchedule::codeFor(Carbon::parse('2017-12-31')));
        $this->assertSame('VAT5', SaudiVatSchedule::codeFor(Carbon::parse('2018-01-01')));
        $this->assertSame('VAT5', SaudiVatSchedule::codeFor(Carbon::parse('2020-06-30')));
        $this->assertSame('VAT15', SaudiVatSchedule::codeFor(Carbon::parse('2020-07-01')));
    }
}
