<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\RevenueSchedule;
use App\Models\RevenueScheduleLine;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E6 — Revenue recognition (IFRS 15). Defers an amount and earns it evenly over
 * a period. Creating the schedule moves the amount into deferred revenue
 * (Dr revenue / Cr deferred); each month's recognition run earns a slice
 * (Dr deferred / Cr revenue).
 */
final class RevenueRecognitionService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    public function createSchedule(string $name, float $total, int $deferredAccountId, int $revenueAccountId, Carbon $start, Carbon $end, int $userId): RevenueSchedule
    {
        $total = round($total, 2);
        if ($total <= 0 || $end->lt($start)) {
            throw new FinanceRuleException('A schedule needs a positive amount and an end on/after the start.');
        }

        $deferredId = $this->resolvePostable($deferredAccountId);
        $revenueId = $this->resolvePostable($revenueAccountId);
        $months = $this->monthEnds($start, $end);
        $per = round($total / count($months), 2);

        return DB::transaction(function () use ($name, $total, $deferredAccountId, $revenueAccountId, $deferredId, $revenueId, $start, $end, $months, $per, $userId): RevenueSchedule {
            $schedule = RevenueSchedule::query()->create([
                'reference' => $this->numbers->next('revenue_schedule', $start),
                'name' => $name, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'total_amount' => $total, 'recognized_amount' => 0,
                'deferred_account_id' => $deferredAccountId, 'revenue_account_id' => $revenueAccountId,
                'status' => 'active', 'created_by' => $userId,
            ]);

            // Even split with the rounding remainder on the final month.
            $allocated = 0.0;
            $count = count($months);
            foreach ($months as $i => $monthEnd) {
                $amount = $i === $count - 1 ? round($total - $allocated, 2) : $per;
                $allocated = round($allocated + $amount, 2);
                $schedule->lines()->create(['period_date' => $monthEnd->toDateString(), 'amount' => $amount, 'recognized' => false]);
            }

            // Defer the upfront amount: Dr revenue / Cr deferred.
            $this->posting->post($schedule, $start, [
                new PostingLine($revenueId, $total, 0.0, 'SAR', 1.0, null, 'Defer revenue — '.$name),
                new PostingLine($deferredId, 0.0, $total, 'SAR', 1.0, null, 'Deferred revenue — '.$name),
            ], $userId);

            return $schedule->load('lines');
        });
    }

    /**
     * Recognise every due, unrecognised slice up to $asOf.
     *
     * @return array{recognized: int, amount: string}
     */
    public function recognizeDue(Carbon $asOf, int $userId): array
    {
        $lines = RevenueScheduleLine::query()
            ->where('recognized', false)
            ->whereDate('period_date', '<=', $asOf->toDateString())
            ->with('revenueSchedule')
            ->get();

        $count = 0;
        $total = 0.0;

        foreach ($lines as $line) {
            /** @var RevenueSchedule $schedule */
            $schedule = $line->revenueSchedule;
            $amount = round((float) $line->amount, 2);
            if ($amount <= 0) {
                $line->update(['recognized' => true]);
                continue;
            }

            DB::transaction(function () use ($schedule, $line, $amount, $userId): void {
                $rows = $this->posting->post($schedule, Carbon::parse((string) $line->period_date), [
                    new PostingLine($this->resolvePostable((int) $schedule->deferred_account_id), $amount, 0.0, 'SAR', 1.0, null, 'Earn revenue — '.$schedule->name),
                    new PostingLine($this->resolvePostable((int) $schedule->revenue_account_id), 0.0, $amount, 'SAR', 1.0, null, 'Recognised revenue — '.$schedule->name),
                ], $userId);

                $line->update(['recognized' => true, 'batch_number' => $rows->first()->batch_number]);
                $schedule->increment('recognized_amount', $amount);
                if (round((float) $schedule->fresh()->recognized_amount, 2) >= round((float) $schedule->total_amount, 2)) {
                    $schedule->update(['status' => 'completed']);
                }
            });

            $count++;
            $total = round($total + $amount, 2);
        }

        return ['recognized' => $count, 'amount' => number_format($total, 2, '.', '')];
    }

    /** @return list<Carbon> month-end dates from start..end inclusive. */
    private function monthEnds(Carbon $start, Carbon $end): array
    {
        $out = [];
        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $out[] = $cursor->copy()->endOfMonth();
            $cursor->addMonth();
        }

        return $out === [] ? [$end->copy()->endOfMonth()] : $out;
    }
}
