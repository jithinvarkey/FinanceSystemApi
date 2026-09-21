<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Recurrence cadence for recurring journal templates.
 */
enum RecurringFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /** Next occurrence after $from, anchored to $dayOfMonth (1..28). */
    public function nextAfter(Carbon $from, int $dayOfMonth): Carbon
    {
        $next = match ($this) {
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Quarterly => $from->copy()->addMonthsNoOverflow(3),
            self::Yearly => $from->copy()->addYearNoOverflow(),
        };

        return $next->setDay(min($dayOfMonth, 28));
    }
}
