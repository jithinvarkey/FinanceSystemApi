<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PeriodStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Repositories\Contracts\FiscalYearRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIN-0002 — Fiscal year lifecycle: creation with auto-generated monthly
 * periods, and FIN-0040 — sequential period close.
 */
final class FiscalYearService
{
    public function __construct(
        private readonly FiscalYearRepositoryInterface $fiscalYears,
    ) {
    }

    /**
     * Create a fiscal year and generate its monthly periods.
     *
     * @param array{code: string, name: string, start_date: string, end_date: string} $data
     *
     * @throws FinanceRuleException When the date range overlaps an existing year
     */
    public function createWithPeriods(array $data, int $userId): FiscalYear
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->endOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new FinanceRuleException('Fiscal year end date must be after the start date.');
        }

        if ($this->fiscalYears->overlaps($start, $end)) {
            throw new FinanceRuleException('Fiscal year rejected: the date range overlaps an existing fiscal year.');
        }

        return DB::transaction(function () use ($data, $start, $end, $userId): FiscalYear {
            /** @var FiscalYear $year */
            $year = $this->fiscalYears->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => 'open',
                'created_by' => $userId,
            ]);

            $cursor = $start->copy();
            $number = 1;

            while ($cursor->lessThan($end)) {
                $periodEnd = $cursor->copy()->endOfMonth()->min($end);

                $year->periods()->create([
                    'period_number' => $number,
                    'name' => $cursor->format('M Y'),
                    'start_date' => $cursor->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'status' => PeriodStatus::Open,
                ]);

                $cursor = $periodEnd->copy()->addDay()->startOfDay();
                $number++;
            }

            return $year->load('periods');
        });
    }

    /**
     * FIN-0040 — Close a period. Periods must close in sequence so no
     * earlier period remains open behind a closed one.
     *
     * @throws FinanceRuleException
     */
    public function closePeriod(int $periodId, int $userId): FiscalPeriod
    {
        /** @var FiscalPeriod $period */
        $period = FiscalPeriod::query()->with('fiscalYear')->findOrFail($periodId);

        if ($period->status === PeriodStatus::Closed) {
            throw new FinanceRuleException("Period {$period->name} is already closed.");
        }

        $earlierOpen = FiscalPeriod::query()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('period_number', '<', $period->period_number)
            ->where('status', '!=', PeriodStatus::Closed)
            ->orderBy('period_number')
            ->first();

        if ($earlierOpen !== null) {
            throw new FinanceRuleException(
                "Close {$earlierOpen->name} first — periods must be closed in sequence.",
            );
        }

        $period->update([
            'status' => PeriodStatus::Closed,
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);

        return $period->refresh();
    }

    /**
     * Reopen a closed period (audit-logged, restricted to supervisors via policy).
     */
    public function reopenPeriod(int $periodId): FiscalPeriod
    {
        /** @var FiscalPeriod $period */
        $period = FiscalPeriod::query()->findOrFail($periodId);

        $period->update(['status' => PeriodStatus::Open, 'closed_by' => null, 'closed_at' => null]);

        return $period->refresh();
    }
}
