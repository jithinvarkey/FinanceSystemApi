<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\TaxCode;
use Illuminate\Support\Carbon;

/**
 * The Saudi (ZATCA) standard-VAT rate timeline.
 *
 * History imported back to 2015 crosses three regimes, so the standard rate is
 * date-effective — never a constant. This resolves the correct standard tax
 * code for a document date. VAT15 remains the live default for new documents;
 * VAT5 / NOVAT exist only for historical (pre-July-2020) imports.
 *
 *   < 2018-01-01            → NOVAT  (no VAT regime in KSA)
 *   2018-01-01 .. 2020-06-30 → VAT5
 *   >= 2020-07-01           → VAT15  (live default)
 *
 * See docs/finance-design/insurance-broker-transaction-flow.md §16.
 */
final class SaudiVatSchedule
{
    /** KSA introduced VAT at 5% on this date. */
    public const VAT_START = '2018-01-01';

    /** Standard rate increased from 5% to 15% on this date. */
    public const RATE_INCREASE = '2020-07-01';

    /**
     * The standard tax-code identifier in force on $date.
     * Pure (no DB) so the business rule is cheap to unit-test.
     */
    public static function codeFor(Carbon $date): string
    {
        $day = $date->copy()->startOfDay();

        if ($day->lt(Carbon::parse(self::VAT_START))) {
            return 'NOVAT';
        }

        if ($day->lt(Carbon::parse(self::RATE_INCREASE))) {
            return 'VAT5';
        }

        return 'VAT15';
    }

    /** The standard rate (percentage) in force on $date. */
    public static function rateFor(Carbon $date): float
    {
        return match (self::codeFor($date)) {
            'VAT15' => 15.0,
            'VAT5' => 5.0,
            default => 0.0,
        };
    }

    /** Resolve the seeded TaxCode row for the standard rate on $date. */
    public static function resolve(Carbon $date): ?TaxCode
    {
        return TaxCode::query()->where('code', self::codeFor($date))->first();
    }
}
